<?php

declare(strict_types=1);

namespace voku\AgentGraph\Sqlite;

use InvalidArgumentException;
use SplQueue;
use voku\AgentGraph\Graph\GraphRelation;
use voku\AgentGraph\Graph\GraphTraversalResult;
use voku\AgentGraph\Graph\TraversalDirection;

final class GraphStore
{
    private SqliteRelationStore $relations;

    public function __construct(string $databaseFile, bool $readOnly = false)
    {
        $this->relations = new SqliteRelationStore($databaseFile, $readOnly);
    }

    public static function openReadOnly(string $databaseFile): self
    {
        return new self($databaseFile, true);
    }

    /**
     * Replace the complete derived graph from a streaming relation source.
     *
     * @param iterable<GraphRelation> $relations
     */
    public function replace(
        iterable $relations,
        string $sourceRevision,
        string $sourceFingerprint,
        bool $allowEmpty = false,
    ): void {
        if (trim($sourceRevision) === '') {
            throw new InvalidArgumentException('Graph source revision must be non-empty.');
        }
        if (trim($sourceFingerprint) === '') {
            throw new InvalidArgumentException('Graph source fingerprint must be non-empty.');
        }

        $this->relations->replaceRelations(
            $relations,
            sourceRevision: $sourceRevision,
            sourceFingerprint: $sourceFingerprint,
            allowEmpty: $allowEmpty,
        );
    }

    /** @return list<GraphRelation> */
    public function incoming(string $targetId, ?string $kind = null): array
    {
        return $this->relations->incoming($targetId, $kind);
    }

    /** @return list<GraphRelation> */
    public function outgoing(string $sourceId, ?string $kind = null): array
    {
        return $this->relations->outgoing($sourceId, $kind);
    }

    /** @return iterable<GraphRelation> */
    public function relations(): iterable
    {
        return $this->relations->relations();
    }

    /** @return list<string> */
    public function neighbours(string $nodeId, ?string $kind = null): array
    {
        return $this->relations->neighbourIds($nodeId, $kind);
    }

    public function traverse(
        string $startId,
        TraversalDirection $direction,
        int $maximumDepth = 2,
        int $maximumNodes = 100,
        ?string $kind = null,
        ?int $maximumRelations = null,
    ): GraphTraversalResult {
        if ($maximumDepth < 1) {
            throw new InvalidArgumentException('Graph traversal depth must be at least 1.');
        }
        if ($maximumNodes < 1) {
            throw new InvalidArgumentException('Graph traversal node limit must be positive.');
        }
        if ($maximumRelations !== null && $maximumRelations < 1) {
            throw new InvalidArgumentException('Graph traversal relation limit must be positive.');
        }

        /** @var SplQueue<array{id: string, depth: int}> $queue */
        $queue = new SplQueue();
        $queue->enqueue(['id' => $startId, 'depth' => 0]);

        $visited = [$startId => true];
        $nodeIds = [];
        $depthByNodeId = [];
        $relations = [];
        $seenRelations = [];
        $truncated = false;

        while (!$queue->isEmpty()) {
            $current = $queue->dequeue();
            if ($current['depth'] >= $maximumDepth) {
                continue;
            }

            // Fetch one more than the cap so truncation of a high-degree node is detectable
            // without loading its whole relation list.
            $fetchLimit = $maximumRelations === null ? null : $maximumRelations + 1;
            $currentRelations = $direction === TraversalDirection::INCOMING
                ? $this->relations->incoming($current['id'], $kind, $fetchLimit)
                : $this->relations->outgoing($current['id'], $kind, $fetchLimit);

            foreach ($currentRelations as $relation) {
                if (!isset($seenRelations[$relation->id])) {
                    if ($maximumRelations !== null && count($relations) >= $maximumRelations) {
                        $truncated = true;
                        // Excluded relations must not contribute traversal nodes.
                        continue;
                    }
                    $seenRelations[$relation->id] = true;
                    $relations[] = $relation;
                }

                $candidateIds = $direction === TraversalDirection::INCOMING
                    ? [$relation->sourceId]
                    : $relation->targetIds;

                foreach ($candidateIds as $candidateId) {
                    if (isset($visited[$candidateId])) {
                        continue;
                    }
                    if (count($nodeIds) >= $maximumNodes) {
                        $truncated = true;
                        continue;
                    }

                    $depth = $current['depth'] + 1;
                    $visited[$candidateId] = true;
                    $nodeIds[] = $candidateId;
                    $depthByNodeId[$candidateId] = $depth;
                    if ($depth < $maximumDepth) {
                        $queue->enqueue(['id' => $candidateId, 'depth' => $depth]);
                    }
                }
            }
        }

        return new GraphTraversalResult(
            startId: $startId,
            direction: $direction,
            nodeIds: $nodeIds,
            depthByNodeId: $depthByNodeId,
            relations: $relations,
            maximumDepth: $maximumDepth,
            maximumNodes: $maximumNodes,
            truncated: $truncated,
        );
    }

    public function sourceRevision(): ?string
    {
        return $this->relations->sourceRevision();
    }

    public function sourceFingerprint(): ?string
    {
        return $this->relations->sourceFingerprint();
    }

    public function relationCount(): int
    {
        return $this->relations->relationCount();
    }

    /** @return list<string> */
    public function integrityFailures(): array
    {
        return $this->relations->integrityFailures();
    }
}
