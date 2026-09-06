<?php

declare(strict_types=1);

namespace voku\AgentGraph\Sqlite;

use PDO;
use RuntimeException;
use voku\AgentGraph\Graph\GraphProjection;
use voku\AgentGraph\Graph\GraphRelation;

/** Internal indexed read path for target-based relation lookup. */
final class SqliteIncomingRelationQuery
{
    private PDO $pdo;
    private bool $readable = false;

    public function __construct(string $databaseFile)
    {
        $this->pdo = new PDO('sqlite:' . $databaseFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /** @return list<GraphRelation> */
    public function incoming(string $targetId, ?string $kind = null): array
    {
        $this->assertReadable();

        $kindPredicate = $kind === null ? '' : ' AND r.kind = :kind';
        $statement = $this->pdo->prepare(
            'SELECT r.relation_id, r.source_id, r.kind, r.relation_position,
                    t.target_id, t.target_position
             FROM graph_relation_targets matched
             JOIN graph_relations r ON r.relation_id = matched.relation_id
             JOIN graph_relation_targets t ON t.relation_id = r.relation_id
             WHERE matched.target_id = :node_id' . $kindPredicate . '
             ORDER BY r.relation_position, t.target_position',
        );
        $parameters = ['node_id' => $targetId];
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

    private function assertReadable(): void
    {
        if ($this->readable) {
            return;
        }

        $schemaVersion = $this->meta('schema_version');
        if ($schemaVersion !== SqliteRelationStore::SCHEMA_VERSION) {
            throw new RuntimeException(sprintf(
                'Graph store schema version is incompatible: expected %s, got %s.',
                SqliteRelationStore::SCHEMA_VERSION,
                $schemaVersion ?? 'missing',
            ));
        }

        $projectionVersion = $this->meta('projection_version');
        if ($projectionVersion === null) {
            throw new RuntimeException('Graph store has no published projection.');
        }
        if ($projectionVersion !== GraphProjection::VERSION) {
            throw new RuntimeException('Graph store projection version is incompatible: ' . $projectionVersion);
        }

        $this->readable = true;
    }

    private function meta(string $key): ?string
    {
        $statement = $this->pdo->prepare('SELECT value FROM graph_meta WHERE key = :key');
        $statement->execute(['key' => $key]);
        $value = $statement->fetchColumn();

        return is_string($value) ? $value : null;
    }

    private function stringColumn(mixed $value, string $column): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new RuntimeException('SQLite graph column is not scalar: ' . $column);
        }

        return (string) $value;
    }
}
