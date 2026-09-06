<?php

declare(strict_types=1);

namespace voku\AgentGraph\Sqlite;

use PDO;
use RuntimeException;
use Throwable;
use voku\AgentGraph\Graph\GraphProjection;
use voku\AgentGraph\Graph\GraphProjectionValidator;
use voku\AgentGraph\Graph\GraphRelation;
use voku\AgentGraph\Graph\GraphValidationException;

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
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA synchronous = NORMAL');
        $this->migrate();
    }

    public function replace(GraphProjection $projection, bool $allowEmpty = false): void
    {
        $report = (new GraphProjectionValidator())->validate($projection, $allowEmpty);
        if ($report->failed()) {
            throw new GraphValidationException($report);
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

            foreach ($projection->relations as $relationPosition => $relation) {
                $relationInsert->execute([
                    'relation_id' => $relation->id,
                    'relation_position' => $relationPosition,
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
            }

            $this->setMeta('projection_version', $projection->version);
            $this->setMeta('relation_count', (string) count($projection->relations));
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

        return $this->relationsFor(
            'r.source_id = :node_id',
            $sourceId,
            $kind,
        );
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

    public function projectionVersion(): ?string
    {
        return $this->meta('projection_version');
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

    /**
     * @return list<GraphRelation>
     */
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
            $relationId = (string) $row['relation_id'];
            if (!isset($grouped[$relationId])) {
                $grouped[$relationId] = [
                    'source_id' => (string) $row['source_id'],
                    'kind' => (string) $row['kind'],
                    'target_ids' => [],
                ];
                $order[] = $relationId;
            }
            $grouped[$relationId]['target_ids'][] = (string) $row['target_id'];
        }

        $relations = [];
        foreach ($order as $relationId) {
            $row = $grouped[$relationId];
            $relations[] = new GraphRelation(
                $relationId,
                $row['source_id'],
                $row['kind'],
                $row['target_ids'],
            );
        }

        return $relations;
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
}
