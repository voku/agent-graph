<?php

declare(strict_types=1);

namespace voku\AgentGraph\Sqlite;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;
use voku\AgentGraph\Graph\GraphProjection;
use voku\AgentGraph\Graph\GraphProjectionValidator;
use voku\AgentGraph\Graph\GraphRelation;
use voku\AgentGraph\Graph\GraphValidationException;
use voku\AgentGraph\Graph\GraphValidationIssue;
use voku\AgentGraph\Graph\GraphValidationReport;

final class SqliteRelationStore
{
    public const SCHEMA_VERSION = '1';

    private PDO $pdo;

    public function __construct(private readonly string $databaseFile)
    {
        $directory = dirname($databaseFile);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create graph index directory: ' . $directory);
        }

        $this->pdo = new PDO('sqlite:' . $databaseFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->migrate();
    }

    public function replace(GraphProjection $projection, bool $allowEmpty = false): void
    {
        $report = (new GraphProjectionValidator())->validate($projection, $allowEmpty);
        if ($report->failed()) {
            throw new GraphValidationException($report);
        }

        $this->replaceRelations(
            $projection->relations,
            projectionVersion: $projection->version,
            allowEmpty: $allowEmpty,
        );
    }

    /**
     * Replace the complete graph without requiring the caller to materialize all relations in memory.
     *
     * @param iterable<GraphRelation> $relations
     */
    public function replaceRelations(
        iterable $relations,
        string $projectionVersion = GraphProjection::VERSION,
        ?string $sourceRevision = null,
        ?string $sourceFingerprint = null,
        bool $allowEmpty = false,
    ): void {
        if ($projectionVersion !== GraphProjection::VERSION) {
            throw new GraphValidationException(new GraphValidationReport([
                new GraphValidationIssue(
                    GraphValidationIssue::ERROR,
                    'projection.unsupported_version',
                    sprintf('Unsupported graph projection version "%s".', $projectionVersion),
                ),
            ]));
        }
        if (($sourceRevision === null) !== ($sourceFingerprint === null)) {
            throw new InvalidArgumentException('Graph source revision and fingerprint must be supplied together.');
        }
        if ($sourceRevision !== null && trim($sourceRevision) === '') {
            throw new InvalidArgumentException('Graph source revision must be non-empty when supplied.');
        }
        if ($sourceFingerprint !== null && trim($sourceFingerprint) === '') {
            throw new InvalidArgumentException('Graph source fingerprint must be non-empty when supplied.');
        }

        $this->assertSchemaCompatible();
        $this->pdo->beginTransaction();

        try {
            $this->pdo->exec('DELETE FROM graph_relation_targets');
            $this->pdo->exec('DELETE FROM graph_relations');

            $relationInsert = $this->pdo->prepare(
                'INSERT INTO graph_relations (relation_id, relation_position, source_id, kind)
                 VALUES (:relation_id, :relation_position, :source_id, :kind)',
            );
            $targetInsert = $this->pdo->prepare(
                'INSERT INTO graph_relation_targets (relation_id, target_id, target_position)
                 VALUES (:relation_id, :target_id, :target_position)',
            );

            $relationCount = 0;
            foreach ($relations as $relation) {
                $this->assertValidRelation($relation);

                $relationInsert->execute([
                    'relation_id' => $relation->id,
                    'relation_position' => $relationCount,
                    'source_id' => $relation->sourceId,
                    'kind' => $relation->kind,
                ]);

                foreach ($relation->targetIds as $targetPosition => $targetId) {
                    $targetInsert->execute([
                        'relation_id' => $relation->id,
                        'target_id' => $targetId,
                        'target_position' => $targetPosition,
                    ]);
                }
                ++$relationCount;
            }

            if ($relationCount === 0 && !$allowEmpty) {
                throw new GraphValidationException(new GraphValidationReport([
                    new GraphValidationIssue(
                        GraphValidationIssue::ERROR,
                        'projection.empty',
                        'Graph projection is empty but emptiness was not explicitly allowed.',
                    ),
                ]));
            }

            $this->setMeta('projection_version', $projectionVersion);
            $this->setMeta('relation_count', (string) $relationCount);
            if ($sourceRevision === null) {
                $this->deleteMeta('source_revision');
                $this->deleteMeta('source_fingerprint');
            } else {
                $this->setMeta('source_revision', $sourceRevision);
                $this->setMeta('source_fingerprint', (string) $sourceFingerprint);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /** @return list<GraphRelation> */
    public function outgoing(string $sourceId, ?string $kind = null): array
    {
        $this->assertReadable();

        return $this->relationsFor('r.source_id = :node_id', $sourceId, $kind);
    }

    /** @return list<GraphRelation> */
    public function incoming(string $targetId, ?string $kind = null): array
    {
        $this->assertReadable();

        return $this->relationsFor(
            'EXISTS (
                SELECT 1 FROM graph_relation_targets matched
                WHERE matched.relation_id = r.relation_id AND matched.target_id = :node_id
            )',
            $targetId,
            $kind,
        );
    }

    /** @return iterable<GraphRelation> */
    public function relations(): iterable
    {
        $this->assertReadable();

        $statement = $this->pdo->query(
            'SELECT r.relation_id, r.source_id, r.kind,
                    t.target_id, t.target_position
             FROM graph_relations r
             JOIN graph_relation_targets t ON t.relation_id = r.relation_id
             ORDER BY r.relation_position, t.target_position',
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to read graph relations.');
        }

        $relationId = null;
        $sourceId = '';
        $kind = '';
        $targetIds = [];

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_array($row)) {
                throw new RuntimeException('SQLite graph relation row is not an array.');
            }

            $currentRelationId = $this->stringColumn($row['relation_id'] ?? null, 'relation_id');
            if ($relationId !== null && $currentRelationId !== $relationId) {
                yield new GraphRelation($relationId, $sourceId, $kind, $targetIds);
                $targetIds = [];
            }

            if ($relationId === null || $currentRelationId !== $relationId) {
                $relationId = $currentRelationId;
                $sourceId = $this->stringColumn($row['source_id'] ?? null, 'source_id');
                $kind = $this->stringColumn($row['kind'] ?? null, 'kind');
            }

            $targetIds[] = $this->stringColumn($row['target_id'] ?? null, 'target_id');
        }

        if ($relationId !== null) {
            yield new GraphRelation($relationId, $sourceId, $kind, $targetIds);
        }
    }

    public function projectionVersion(): ?string
    {
        return $this->meta('projection_version');
    }

    public function sourceRevision(): ?string
    {
        return $this->meta('source_revision');
    }

    public function sourceFingerprint(): ?string
    {
        return $this->meta('source_fingerprint');
    }

    public function relationCount(): int
    {
        $value = $this->meta('relation_count');

        return $value === null ? 0 : (int) $value;
    }

    /** @return list<string> */
    public function integrityFailures(): array
    {
        $failures = [];

        $statement = $this->pdo->query('PRAGMA integrity_check');
        if ($statement !== false) {
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $row) {
                if (is_string($row) && $row !== 'ok') {
                    $failures[] = 'sqlite_integrity:' . $row;
                }
            }
        }

        $foreignKeys = $this->pdo->query('PRAGMA foreign_key_check');
        if ($foreignKeys !== false && $foreignKeys->fetch(PDO::FETCH_ASSOC) !== false) {
            $failures[] = 'foreign_key_check_failed';
        }

        if ($this->meta('schema_version') !== self::SCHEMA_VERSION) {
            $failures[] = 'schema_version_mismatch';
        }

        $projectionVersion = $this->projectionVersion();
        if ($projectionVersion !== null && $projectionVersion !== GraphProjection::VERSION) {
            $failures[] = 'projection_version_mismatch';
        }

        $sourceRevision = $this->sourceRevision();
        $sourceFingerprint = $this->sourceFingerprint();
        if (($sourceRevision === null) !== ($sourceFingerprint === null)) {
            $failures[] = 'source_provenance_incomplete';
        }

        return $failures;
    }

    private function migrate(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS graph_meta (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )',
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS graph_relations (
                relation_id TEXT PRIMARY KEY,
                relation_position INTEGER NOT NULL UNIQUE,
                source_id TEXT NOT NULL,
                kind TEXT NOT NULL
            )',
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS graph_relation_targets (
                relation_id TEXT NOT NULL,
                target_id TEXT NOT NULL,
                target_position INTEGER NOT NULL,
                PRIMARY KEY (relation_id, target_position),
                UNIQUE (relation_id, target_id),
                FOREIGN KEY (relation_id) REFERENCES graph_relations(relation_id) ON DELETE CASCADE
            )',
        );
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS graph_relations_source_kind ON graph_relations(source_id, kind)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS graph_relation_targets_target ON graph_relation_targets(target_id, relation_id)');

        $schemaVersion = $this->meta('schema_version');
        if ($schemaVersion === null) {
            $this->setMeta('schema_version', self::SCHEMA_VERSION);

            return;
        }

        $this->assertSchemaCompatible();
    }

    private function assertReadable(): void
    {
        $this->assertSchemaCompatible();

        $projectionVersion = $this->projectionVersion();
        if ($projectionVersion === null) {
            throw new RuntimeException('Graph store has no published projection.');
        }
        if ($projectionVersion !== GraphProjection::VERSION) {
            throw new RuntimeException('Graph store projection version is incompatible: ' . $projectionVersion);
        }
    }

    private function assertSchemaCompatible(): void
    {
        $schemaVersion = $this->meta('schema_version');
        if ($schemaVersion !== self::SCHEMA_VERSION) {
            throw new RuntimeException(sprintf(
                'Graph store schema version is incompatible: expected %s, got %s.',
                self::SCHEMA_VERSION,
                $schemaVersion ?? 'missing',
            ));
        }
    }

    private function assertValidRelation(GraphRelation $relation): void
    {
        $issues = [];
        if (trim($relation->id) === '') {
            $issues[] = new GraphValidationIssue(GraphValidationIssue::ERROR, 'relation.empty_id', 'Relation id must be non-empty.');
        }
        if (trim($relation->sourceId) === '') {
            $issues[] = new GraphValidationIssue(GraphValidationIssue::ERROR, 'relation.empty_source', 'Relation source id must be non-empty.', $relation->id !== '' ? $relation->id : null);
        }
        if (trim($relation->kind) === '') {
            $issues[] = new GraphValidationIssue(GraphValidationIssue::ERROR, 'relation.empty_kind', 'Relation kind must be non-empty.', $relation->id !== '' ? $relation->id : null);
        }
        if ($relation->targetIds === []) {
            $issues[] = new GraphValidationIssue(GraphValidationIssue::ERROR, 'relation.empty_targets', 'Relation must contain at least one target id.', $relation->id !== '' ? $relation->id : null);
        }

        $seenTargets = [];
        foreach ($relation->targetIds as $targetId) {
            if (trim($targetId) === '') {
                $issues[] = new GraphValidationIssue(GraphValidationIssue::ERROR, 'relation.empty_target', 'Relation target id must be non-empty.', $relation->id !== '' ? $relation->id : null);
                continue;
            }
            if (isset($seenTargets[$targetId])) {
                $issues[] = new GraphValidationIssue(GraphValidationIssue::ERROR, 'relation.duplicate_target', 'Relation target ids must be unique within one relation.', $relation->id !== '' ? $relation->id : null);
                continue;
            }
            $seenTargets[$targetId] = true;
        }

        if ($issues !== []) {
            throw new GraphValidationException(new GraphValidationReport($issues));
        }
    }

    /** @return list<GraphRelation> */
    private function relationsFor(string $predicate, string $nodeId, ?string $kind): array
    {
        $kindPredicate = $kind === null ? '' : ' AND r.kind = :kind';
        $statement = $this->pdo->prepare(
            'SELECT r.relation_id, r.source_id, r.kind, r.relation_position,
                    t.target_id, t.target_position
             FROM graph_relations r
             JOIN graph_relation_targets t ON t.relation_id = r.relation_id
             WHERE ' . $predicate . $kindPredicate . '
             ORDER BY r.relation_position, t.target_position',
        );
        $parameters = ['node_id' => $nodeId];
        if ($kind !== null) {
            $parameters['kind'] = $kind;
        }
        $statement->execute($parameters);

        /** @var array<string, array{source_id: string, kind: string, target_ids: list<string>}> $grouped */
        $grouped = [];
        /** @var list<string> $order */
        $order = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('SQLite graph relation row is not an array.');
            }

            $relationId = $this->stringColumn($row['relation_id'] ?? null, 'relation_id');
            if (!isset($grouped[$relationId])) {
                $grouped[$relationId] = [
                    'source_id' => $this->stringColumn($row['source_id'] ?? null, 'source_id'),
                    'kind' => $this->stringColumn($row['kind'] ?? null, 'kind'),
                    'target_ids' => [],
                ];
                $order[] = $relationId;
            }
            $grouped[$relationId]['target_ids'][] = $this->stringColumn($row['target_id'] ?? null, 'target_id');
        }

        $relations = [];
        foreach ($order as $relationId) {
            $row = $grouped[$relationId];
            $relations[] = new GraphRelation($relationId, $row['source_id'], $row['kind'], $row['target_ids']);
        }

        return $relations;
    }

    private function stringColumn(mixed $value, string $column): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new RuntimeException('SQLite graph column is not scalar: ' . $column);
        }

        return (string) $value;
    }

    private function meta(string $key): ?string
    {
        $statement = $this->pdo->prepare('SELECT value FROM graph_meta WHERE key = :key');
        $statement->execute(['key' => $key]);
        $value = $statement->fetchColumn();

        return is_string($value) ? $value : null;
    }

    private function setMeta(string $key, string $value): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO graph_meta (key, value) VALUES (:key, :value)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value',
        );
        $statement->execute(['key' => $key, 'value' => $value]);
    }

    private function deleteMeta(string $key): void
    {
        $statement = $this->pdo->prepare('DELETE FROM graph_meta WHERE key = :key');
        $statement->execute(['key' => $key]);
    }
}
