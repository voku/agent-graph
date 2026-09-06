<?php

declare(strict_types=1);

namespace voku\AgentGraph\Tests\Sqlite;

use PHPUnit\Framework\TestCase;
use voku\AgentGraph\Graph\GraphRelation;
use voku\AgentGraph\Graph\GraphValidationException;
use voku\AgentGraph\Graph\TraversalDirection;
use voku\AgentGraph\Sqlite\GraphStore;

final class GraphStoreTest extends TestCase
{
    private string $databaseFile;

    protected function setUp(): void
    {
        $this->databaseFile = sys_get_temp_dir() . '/agent-graph-store-' . bin2hex(random_bytes(8)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        if (is_file($this->databaseFile)) {
            unlink($this->databaseFile);
        }
    }

    public function testStreamingReplacementPreservesProvenanceAndIndexedReads(): void
    {
        $relations = (static function (): iterable {
            yield new GraphRelation('r2', 'source', 'calls', ['target-b', 'target-a']);
            yield new GraphRelation('r1', 'source', 'extends', ['target-a']);
            yield new GraphRelation('r3', 'other', 'calls', ['target-a']);
        })();

        $store = new GraphStore($this->databaseFile);
        $store->replace($relations, 'map:abc', 'sha256:def');

        self::assertSame('map:abc', $store->sourceRevision());
        self::assertSame('sha256:def', $store->sourceFingerprint());
        self::assertSame(3, $store->relationCount());
        self::assertSame(['r2', 'r1'], $this->ids($store->outgoing('source')));
        self::assertSame(['r2'], $this->ids($store->outgoing('source', 'calls')));
        self::assertSame(['r2', 'r1', 'r3'], $this->ids($store->incoming('target-a')));
        self::assertSame(['target-b', 'target-a'], $store->outgoing('source', 'calls')[0]->targetIds);
        self::assertSame([], $store->integrityFailures());
    }

    public function testStoredRelationsCanBeStreamedInInsertionOrder(): void
    {
        $store = new GraphStore($this->databaseFile);
        $store->replace([
            new GraphRelation('r2', 'source', 'calls', ['target-b', 'target-a']),
            new GraphRelation('r1', 'source', 'extends', ['target-a']),
            new GraphRelation('r3', 'other', 'calls', ['target-a']),
        ], 'map:stream', 'sha256:stream');

        $relations = iterator_to_array($store->relations(), false);

        self::assertSame(['r2', 'r1', 'r3'], $this->ids($relations));
        self::assertSame(['target-b', 'target-a'], $relations[0]->targetIds);
    }

    public function testNeighboursAreUniqueAndDeterministicallySorted(): void
    {
        $store = new GraphStore($this->databaseFile);
        $store->replace([
            new GraphRelation('r1', 'alpha', 'calls', ['center']),
            new GraphRelation('r2', 'center', 'calls', ['zulu', 'beta']),
            new GraphRelation('r3', 'center', 'calls', ['alpha']),
            new GraphRelation('r4', 'center', 'extends', ['ignored-for-filter']),
        ], 'map:1', 'sha256:1');

        self::assertSame(['alpha', 'beta', 'ignored-for-filter', 'zulu'], $store->neighbours('center'));
        self::assertSame(['alpha', 'beta', 'zulu'], $store->neighbours('center', 'calls'));
    }

    public function testTraversalIsBoundedCycleSafeAndDeterministic(): void
    {
        $store = new GraphStore($this->databaseFile);
        $store->replace([
            new GraphRelation('r1', 'A', 'calls', ['B']),
            new GraphRelation('r2', 'B', 'calls', ['C']),
            new GraphRelation('r3', 'C', 'calls', ['A']),
            new GraphRelation('r4', 'B', 'calls', ['D']),
            new GraphRelation('r5', 'D', 'extends', ['E']),
        ], 'map:2', 'sha256:2');

        $result = $store->traverse('A', TraversalDirection::OUTGOING, maximumDepth: 3, maximumNodes: 10);

        self::assertSame(['B', 'C', 'D', 'E'], $result->nodeIds);
        self::assertSame(1, $result->depthOf('B'));
        self::assertSame(2, $result->depthOf('C'));
        self::assertSame(2, $result->depthOf('D'));
        self::assertSame(3, $result->depthOf('E'));
        self::assertSame(['r1', 'r2', 'r4', 'r3', 'r5'], $this->ids($result->relations));
        self::assertFalse($result->truncated);

        $limited = $store->traverse('A', TraversalDirection::OUTGOING, maximumDepth: 3, maximumNodes: 2, kind: 'calls');
        self::assertSame(['B', 'C'], $limited->nodeIds);
        self::assertTrue($limited->truncated);
    }

    public function testIncomingTraversalWalksSources(): void
    {
        $store = new GraphStore($this->databaseFile);
        $store->replace([
            new GraphRelation('r1', 'A', 'calls', ['B']),
            new GraphRelation('r2', 'C', 'calls', ['B']),
            new GraphRelation('r3', 'D', 'calls', ['C']),
        ], 'map:3', 'sha256:3');

        $result = $store->traverse('B', TraversalDirection::INCOMING, maximumDepth: 2, maximumNodes: 10, kind: 'calls');

        self::assertSame(['A', 'C', 'D'], $result->nodeIds);
        self::assertSame(['r1', 'r2', 'r3'], $this->ids($result->relations));
    }

    public function testStreamingEmptyGraphRequiresExplicitOptIn(): void
    {
        $store = new GraphStore($this->databaseFile);

        try {
            $store->replace([], 'map:4', 'sha256:4');
            self::fail('Expected accidental empty graph to fail.');
        } catch (GraphValidationException $exception) {
            self::assertSame('projection.empty', $exception->report->errors()[0]->code);
        }

        $store->replace([], 'map:4', 'sha256:4', allowEmpty: true);
        self::assertSame(0, $store->relationCount());
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
