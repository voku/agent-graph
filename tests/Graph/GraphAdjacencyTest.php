<?php

declare(strict_types=1);

namespace voku\AgentGraph\Tests\Graph;

use PHPUnit\Framework\TestCase;
use voku\AgentGraph\Graph\GraphAdjacency;
use voku\AgentGraph\Graph\GraphProjection;
use voku\AgentGraph\Graph\GraphRelation;

final class GraphAdjacencyTest extends TestCase
{
    public function testIncomingOutgoingPreserveProjectionOrder(): void
    {
        $adjacency = new GraphAdjacency(new GraphProjection([
            new GraphRelation('r2', 'source', 'calls', ['target', 'other']),
            new GraphRelation('r1', 'source', 'extends', ['target']),
            new GraphRelation('r3', 'other-source', 'calls', ['target']),
        ]));

        self::assertSame(['r2', 'r1'], $this->ids($adjacency->outgoing('source')));
        self::assertSame(['r2'], $this->ids($adjacency->outgoing('source', 'calls')));
        self::assertSame(['r2', 'r1', 'r3'], $this->ids($adjacency->incoming('target')));
        self::assertSame(['r2', 'r3'], $this->ids($adjacency->incoming('target', 'calls')));
        self::assertSame([], $adjacency->incoming('missing'));
    }

    /**
     * @param list<GraphRelation> $relations
     * @return list<string>
     */
    private function ids(array $relations): array
    {
        return array_map(static fn (GraphRelation $relation): string => $relation->id, $relations);
    }
}
