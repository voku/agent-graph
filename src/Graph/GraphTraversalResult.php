<?php

declare(strict_types=1);

namespace voku\AgentGraph\Graph;

final readonly class GraphTraversalResult
{
    /**
     * @param list<string> $nodeIds
     * @param array<string, int> $depthByNodeId
     * @param list<GraphRelation> $relations
     */
    public function __construct(
        public string $startId,
        public TraversalDirection $direction,
        public array $nodeIds,
        public array $depthByNodeId,
        public array $relations,
        public int $maximumDepth,
        public int $maximumNodes,
        public bool $truncated,
    ) {
    }

    public function depthOf(string $nodeId): ?int
    {
        return $this->depthByNodeId[$nodeId] ?? null;
    }
}
