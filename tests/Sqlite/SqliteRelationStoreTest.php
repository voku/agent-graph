<?php

declare(strict_types=1);

namespace voku\AgentGraph\Tests\Sqlite;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentGraph\Graph\GraphProjection;
use voku\AgentGraph\Graph\GraphRelation;
use voku\AgentGraph\Graph\GraphValidationException;
use voku\AgentGraph\Sqlite\SqliteRelationStore;

final class SqliteRelationStoreTest extends TestCase
{
    private string $databaseFile;

    protected function setUp(): void
    {
        $this->databaseFile = sys_get_temp_dir() . '/agent-graph-' . bin2hex(random_bytes(8)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach ([$this->databaseFile, $this->databaseFile . '-wal', $this->databaseFile . '-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testIncomingOutgoingFilteringAndOrderRoundTrip(): void
    {
        $store = new SqliteRelationStore($this->databaseFile);
        $store->replace(new GraphProjection([
            new GraphRelation('r2', 'source', 'calls', ['target-b', 'target-a']),
            new GraphRelation('r1', 'source', 'extends', ['target-a']),
            new GraphRelation('r3', 'other', 'calls', ['target-a']),
        ]));

        self::assertSame(['r2', 'r1'], $this->ids($store->outgoing('source')));
        self::assertSame(['r2'], $this->ids($store->outgoing('source', 'calls')));
        self::assertSame(['r2', 'r1', 'r3'], $this->ids($store->incoming('target-a')));
        self::assertSame(['r2', 'r3'], $this->ids($store->incoming('target-a', 'calls')));
        self::assertSame(['target-b', 'target-a'], $store->outgoing('source', 'calls')[0]->targetIds);
        self::assertSame(GraphProjection::VERSION, $store->projectionVersion());
        self::assertSame(3, $store->relationCount());
        self::assertSame([], $store->integrityFailures());
    }

    public function testRelationStoreDoesNotRequireWalSidecars(): void
    {
        $store = new SqliteRelationStore($this->databaseFile);
        $store->replace(new GraphProjection([
            new GraphRelation('r1', 'source', 'calls', ['target']),
        ]));

        self::assertFileExists($this->databaseFile);
        self::assertFileDoesNotExist($this->databaseFile . '-wal');
        self::assertFileDoesNotExist($this->databaseFile . '-shm');
    }

    public function testEmptyProjectionRequiresExplicitOptIn(): void
    {
        $store = new SqliteRelationStore($this->databaseFile);

        try {
            $store->replace(new GraphProjection([]));
            self::fail('Expected validation failure for accidental empty graph.');
        } catch (GraphValidationException $exception) {
            self::assertSame('projection.empty', $exception->report->errors()[0]->code);
        }

        $store->replace(new GraphProjection([]), true);
        self::assertSame(0, $store->relationCount());
        self::assertSame([], $store->incoming('anything'));
    }

    public function testFailedReplacementKeepsPreviousValidGraph(): void
    {
        $store = new SqliteRelationStore($this->databaseFile);
        $store->replace(new GraphProjection([
            new GraphRelation('old', 'source', 'calls', ['target']),
        ]));

        try {
            $store->replace(new GraphProjection([
                new GraphRelation('duplicate', 'new', 'calls', ['a']),
                new GraphRelation('duplicate', 'new', 'calls', ['b']),
            ]));
            self::fail('Expected duplicate relation validation failure.');
        } catch (GraphValidationException) {
            self::assertSame(['old'], $this->ids($store->outgoing('source')));
        }
    }

    public function testStoreWithoutPublishedProjectionFailsClosed(): void
    {
        $store = new SqliteRelationStore($this->databaseFile);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no published projection');
        $store->incoming('anything');
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
