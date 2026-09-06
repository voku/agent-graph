<?php

declare(strict_types=1);

namespace voku\AgentGraph\Graph;

final readonly class GraphAdjacency
{
    /** @var array<string, list<GraphRelation>> */
    private array $incoming;

    /** @var array<string, list<GraphRelation>> */
    private array $outgoing;

    public function __construct(GraphProjection $projection)
    {
        $report = (new GraphProjectionValidator())->validate($projection, true);
        if ($report->failed()) {
            throw new GraphValidationException($report);
        }

        $incoming = [];
        $outgoing = [];
        foreach ($projection->relations as $relation) {
            $outgoing[$relation->sourceId][] = $relation;
            foreach ($relation->targetIds as $targetId) {
                $incoming[$targetId][] = $relation;
            }
        }

        $this->incoming = $incoming;
        $this->outgoing = $outgoing;
    }

    /** @return list<GraphRelation> */
    public function incoming(string $nodeId, ?string $kind = null): array
    {
        return $this->filter($this->incoming[$nodeId] ?? [], $kind);
    }

    /** @return list<GraphRelation> */
    public function outgoing(string $nodeId, ?string $kind = null): array
    {
        return $this->filter($this->outgoing[$nodeId] ?? [], $kind);
    }

    /**
     * @param list<GraphRelation> $relations
     * @return list<GraphRelation>
     */
    private function filter(array $relations, ?string $kind): array
    {
        if ($kind === null) {
            return $relations;
        }

        return array_values(array_filter(
            $relations,
            static fn (GraphRelation $relation): bool => $relation->kind === $kind,
        ));
    }
}
