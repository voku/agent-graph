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
    public const SCHEMA_VERSION = '2';
    private const LEGACY_SCHEMA_VERSION = '1';

    /** @var array<string, string> Secondary indexes; dropped during bulk replacement and rebuilt once afterwards. */
    private const SECONDARY_INDEXES = [
        'graph_relations_source_kind' => 'CREATE INDEX IF NOT EXISTS graph_relations_source_kind ON graph_relations(source_id, kind)',
        'graph_relation_targets_target' => 'CREATE INDEX IF NOT EXISTS graph_relation_targets_target ON graph_relation_targets(target_id, relation_position)',
    ];

    private PDO $pdo;
    private bool $readable = false;

    public function __construct(
        private readonly string $databaseFile,
        private readonly bool $readOnly = false,
    ) {
        if ($this->readOnly && !is_file($this->databaseFile)) {
            throw new RuntimeException('Graph store does not exist for read-only access: ' . $this->databaseFile);
        }

        if (!$this->readOnly) {
            $directory = dirname($databaseFile);
            if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create graph index directory: ' . $directory);
            }
        }

        $this->pdo = $this->openConnection($this->readOnly);
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        if ($this->readOnly) {
            $this->assertSchemaCompatible();
        } else {
            $this->migrate();
        }
    }

    private function openConnection(bool $readOnly): PDO
    {
        $dsn = 'sqlite:' . $this->databaseFile;
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

        if (!$readOnly) {
            return new PDO($dsn, null, null, $options);
        }

        if (!defined('PDO::SQLITE_ATTR_OPEN_FLAGS') || !defined('PDO::SQLITE_OPEN_READONLY')) {
            throw new RuntimeException('PDO SQLite read-only open flags are unavailable.');
        }

        $options[(int) constant('PDO::SQLITE_ATTR_OPEN_FLAGS')] = (int) constant('PDO::SQLITE_OPEN_READONLY');

        return new PDO($dsn, null, null, $options);
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

        $this->readable = false;
        $this->assertSchemaCompatible();
        $this->pdo->beginTransaction();

        try {
            // Maintaining the secondary indexes per insert roughly doubles rebuild time; DDL is
            // transactional in SQLite, so a failed replacement rolls the indexes back with the data.
            foreach (array_keys(self::SECONDARY_INDEXES) as $index) {
                $this->pdo->exec('DROP INDEX IF EXISTS ' . $index);
            }
            $this->pdo->exec('DELETE FROM graph_relation_targets');
            $this->pdo->exec('DELETE FROM graph_relations');

            $relationInsert = $this->pdo->prepare(
                'INSERT INTO graph_relations (relation_position, relation_id, source_id, kind)
                 VALUES (:relation_position, :relation_id, :source_id, :kind)',
            );
            $targetInsert = $this->pdo->prepare(
                'INSERT INTO graph_relation_targets (relation_position, target_position, target_id)
                 VALUES (:relation_position, :target_position, :target_id)',
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
                        'relation_position' => $relationCount,
                        'target_position' => $targetPosition,
                        'target_id' => $targetId,
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

            $this->createSecondaryIndexes();

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

    /**
     * @param positive-int|null $limit maximum number of relations (first in canonical order), null for all
     * @return list<GraphRelation>
     */
    public function outgoing(string $sourceId, ?string $kind = null, ?int $limit = null): array
    {
        $this->assertReadable();

        return $this->relationsFor(
            'FROM graph_relations r
             JOIN graph_relation_targets t ON t.relation_position = r.relation_position
             WHERE r.source_id = :node_id',
            'SELECT r.relation_position FROM graph_relations r WHERE r.source_id = :node_id',
            $sourceId,
            $kind,
            $limit,
        );
    }

    /**
     * Starts at the indexed target_id so a lookup does not scan graph_relations.
     *
     * @param positive-int|null $limit maximum number of relations (first in canonical order), null for all
     * @return list<GraphRelation>
     */
    public function incoming(string $targetId, ?string $kind = null, ?int $limit = null): array
    {
        $this->assertReadable();

        return $this->relationsFor(
            'FROM graph_relation_targets matched
             JOIN graph_relations r ON r.relation_position = matched.relation_position
             JOIN graph_relation_targets t ON t.relation_position = r.relation_position
             WHERE matched.target_id = :node_id',
            'SELECT matched.relation_position AS relation_position FROM graph_relation_targets matched
             JOIN graph_relations r ON r.relation_position = matched.relation_position
             WHERE matched.target_id = :node_id',
            $targetId,
            $kind,
            $limit,
        );
    }

    /**
     * Distinct adjacent node ids (either direction, excluding the node itself), sorted bytewise.
     *
     * @return list<string>
     */
    public function neighbourIds(string $nodeId, ?string $kind = null): array
    {
        $this->assertReadable();

        $kindPredicate = $kind === null ? '' : ' AND r.kind = :kind';
        $statement = $this->pdo->prepare(
            'SELECT id FROM (
                SELECT r.source_id AS id
                FROM graph_relation_targets matched
                JOIN graph_relations r ON r.relation_position = matched.relation_position
                WHERE matched.target_id = :node_id' . $kindPredicate . '
                UNION
                SELECT t.target_id AS id
                FROM graph_relations r
                JOIN graph_relation_targets t ON t.relation_position = r.relation_position
                WHERE r.source_id = :node_id' . $kindPredicate . '
             )
             WHERE id <> :node_id
             ORDER BY id',
        );
        $parameters = ['node_id' => $nodeId];
        if ($kind !== null) {
            $parameters['kind'] = $kind;
        }
        $statement->execute($parameters);

        $ids = [];
        while (($id = $statement->fetchColumn()) !== false) {
            $ids[] = $this->stringColumn($id, 'id');
        }

        return $ids;
    }

    /** @return iterable<GraphRelation> */
    public function relations(): iterable
    {
        $this->assertReadable();

        $statement = $this->pdo->query(
            'SELECT r.relation_id, r.source_id, r.kind,
                    t.target_id, t.target_position
             FROM graph_relations r
             JOIN graph_relation_targets t ON t.relation_position = r.relation_position
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

        $schemaVersion = $this->meta('schema_version');
        if ($schemaVersion === self::LEGACY_SCHEMA_VERSION) {
            $this->upgradeLegacySchema();

            return;
        }

        $this->createTables('');
        $this->createSecondaryIndexes();

        if ($schemaVersion === null) {
            $this->setMeta('schema_version', self::SCHEMA_VERSION);

            return;
        }

        $this->assertSchemaCompatible();
    }

    /**
     * Relations are keyed by their integer position instead of the text relation id. That keeps the
     * target rows clustered in canonical order and roughly a third smaller, and it makes the
     * ORDER BY on every lookup free.
     */
    private function createTables(string $suffix): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS graph_relations' . $suffix . ' (
                relation_position INTEGER PRIMARY KEY,
                relation_id TEXT NOT NULL UNIQUE,
                source_id TEXT NOT NULL,
                kind TEXT NOT NULL
            )',
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS graph_relation_targets' . $suffix . ' (
                relation_position INTEGER NOT NULL,
                target_position INTEGER NOT NULL,
                target_id TEXT NOT NULL,
                PRIMARY KEY (relation_position, target_position),
                UNIQUE (relation_position, target_id),
                FOREIGN KEY (relation_position) REFERENCES graph_relations' . $suffix . '(relation_position) ON DELETE CASCADE
            ) WITHOUT ROWID',
        );
    }

    /** Rewrites a version 1 file in place, preserving relation order, targets and provenance meta. */
    private function upgradeLegacySchema(): void
    {
        $this->pdo->beginTransaction();

        try {
            $this->createTables('_v2');
            $this->pdo->exec(
                'INSERT INTO graph_relations_v2 (relation_position, relation_id, source_id, kind)
                 SELECT relation_position, relation_id, source_id, kind
                 FROM graph_relations
                 ORDER BY relation_position',
            );
            $this->pdo->exec(
                'INSERT INTO graph_relation_targets_v2 (relation_position, target_position, target_id)
                 SELECT r.relation_position, t.target_position, t.target_id
                 FROM graph_relation_targets t
                 JOIN graph_relations r ON r.relation_id = t.relation_id
                 ORDER BY r.relation_position, t.target_position',
            );

            $legacyTargets = $this->scalarCount('graph_relation_targets');
            if ($legacyTargets !== $this->scalarCount('graph_relation_targets_v2')) {
                throw new RuntimeException('Graph store schema upgrade lost relation targets.');
            }

            $this->pdo->exec('DROP TABLE graph_relation_targets');
            $this->pdo->exec('DROP TABLE graph_relations');
            $this->pdo->exec('ALTER TABLE graph_relations_v2 RENAME TO graph_relations');
            $this->pdo->exec('ALTER TABLE graph_relation_targets_v2 RENAME TO graph_relation_targets');
            $this->createSecondaryIndexes();
            $this->setMeta('schema_version', self::SCHEMA_VERSION);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        // Return the pages of the dropped legacy tables to the filesystem.
        $this->pdo->exec('VACUUM');
    }

    private function scalarCount(string $table): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM ' . $table);

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    private function createSecondaryIndexes(): void
    {
        foreach (self::SECONDARY_INDEXES as $statement) {
            $this->pdo->exec($statement);
        }
    }

    private function assertReadable(): void
    {
        if ($this->readable) {
            return;
        }

        $this->assertSchemaCompatible();

        $projectionVersion = $this->projectionVersion();
        if ($projectionVersion === null) {
            throw new RuntimeException('Graph store has no published projection.');
        }
        if ($projectionVersion !== GraphProjection::VERSION) {
            throw new RuntimeException('Graph store projection version is incompatible: ' . $projectionVersion);
        }

        $this->readable = true;
    }

    private function assertSchemaCompatible(): void
    {
        foreach (['graph_meta', 'graph_relations', 'graph_relation_targets'] as $table) {
            $statement = $this->pdo->prepare(
                "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table",
            );
            $statement->execute(['table' => $table]);
            if ($statement->fetchColumn() === false) {
                throw new RuntimeException('Graph store schema is missing required table: ' . $table);
            }
        }

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

    /**
     * Rows arrive ordered by relation_position, so each relation's targets are contiguous and are
     * grouped while streaming instead of materializing every row first.
     *
     * Unbounded reads join straight from the indexed lookup. A limit has to apply to relations,
     * not joined target rows, so it selects the first N relation positions and expands only those.
     *
     * @param string $joined FROM/JOIN/WHERE aliasing graph_relations as r and its targets as t
     * @param string $matchingIds SELECT of relation_position for the same match, used when limited
     * @return list<GraphRelation>
     */
    private function relationsFor(string $joined, string $matchingIds, string $nodeId, ?string $kind, ?int $limit): array
    {
        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException('Relation limit must be positive.');
        }

        $kindPredicate = $kind === null ? '' : ' AND r.kind = :kind';
        $from = $limit === null
            ? $joined . $kindPredicate
            : 'FROM graph_relations r
               JOIN graph_relation_targets t ON t.relation_position = r.relation_position
               WHERE r.relation_position IN (' . $matchingIds . $kindPredicate . ' ORDER BY r.relation_position LIMIT ' . $limit . ')';
        $statement = $this->pdo->prepare(
            'SELECT r.relation_id, r.source_id, r.kind, t.target_id
             ' . $from . '
             ORDER BY r.relation_position, t.target_position',
        );
        $parameters = ['node_id' => $nodeId];
        if ($kind !== null) {
            $parameters['kind'] = $kind;
        }
        $statement->execute($parameters);

        $relations = [];
        $relationId = null;
        $sourceId = '';
        $relationKind = '';
        $targetIds = [];

        while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
            $currentRelationId = $this->stringColumn($row[0] ?? null, 'relation_id');
            if ($currentRelationId !== $relationId) {
                if ($relationId !== null) {
                    $relations[] = new GraphRelation($relationId, $sourceId, $relationKind, $targetIds);
                }
                $relationId = $currentRelationId;
                $sourceId = $this->stringColumn($row[1] ?? null, 'source_id');
                $relationKind = $this->stringColumn($row[2] ?? null, 'kind');
                $targetIds = [];
            }
            $targetIds[] = $this->stringColumn($row[3] ?? null, 'target_id');
        }

        if ($relationId !== null) {
            $relations[] = new GraphRelation($relationId, $sourceId, $relationKind, $targetIds);
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
