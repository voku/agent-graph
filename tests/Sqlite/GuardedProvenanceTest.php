<?php

declare(strict_types=1);

namespace voku\AgentGraph\Tests\Sqlite;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentGraph\Graph\GraphProjectionDigest;
use voku\AgentGraph\Graph\GraphRelation;
use voku\AgentGraph\Sqlite\GraphStore;

final class GuardedProvenanceTest extends TestCase
{
    private string $databaseFile;

    protected function setUp(): void
    {
        $this->databaseFile = sys_get_temp_dir() . '/agent-graph-cas-' . bin2hex(random_bytes(9)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach ([$this->databaseFile, $this->databaseFile . '-wal', $this->databaseFile . '-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /** @return list<GraphRelation> */
    private function graph(): array
    {
        return [
            new GraphRelation('r2', 'first', 'calls', ['target-b', 'target-a']),
            new GraphRelation('r1', 'other', 'uses', ['target-a']),
            new GraphRelation('r3', 'third', 'extends', ['target-c']),
        ];
    }

    public function testIdenticalGraphAdvancesBothProvenanceFieldsWithoutRebuildingRows(): void
    {
        $graph = $this->graph();
        $store = new GraphStore($this->databaseFile);
        $store->replace($graph, 'revision:a', 'fingerprint:a');

        $before = array_map(static fn (GraphRelation $relation): array => $relation->toArray(), iterator_to_array($store->relations(), false));
        $db = new PDO('sqlite:' . $this->databaseFile);
        $digestQuery = $db->query("SELECT value FROM graph_meta WHERE key = 'projection_digest_v1'");
        self::assertInstanceOf(\PDOStatement::class, $digestQuery);
        $digest = $digestQuery->fetchColumn();
        self::assertSame(GraphProjectionDigest::forRelations($graph), $digest);

        self::assertTrue($store->advanceProvenanceIfUnchanged(
            (static function () use ($graph): iterable { yield from $graph; })(),
            'revision:a',
            'fingerprint:a',
            'revision:b',
            'fingerprint:b',
        ));

        self::assertSame('revision:b', $store->sourceRevision());
        self::assertSame('fingerprint:b', $store->sourceFingerprint());
        $digestQuery = $db->query("SELECT value FROM graph_meta WHERE key = 'projection_digest_v1'");
        self::assertInstanceOf(\PDOStatement::class, $digestQuery);
        self::assertSame($digest, $digestQuery->fetchColumn());
        self::assertSame($before, array_map(static fn (GraphRelation $relation): array => $relation->toArray(), iterator_to_array($store->relations(), false)));
        self::assertSame([], $store->integrityFailures());
    }

    public function testRejectsStaleRevisionOrFingerprintAndDoesNotPartiallyUpdate(): void
    {
        $store = new GraphStore($this->databaseFile);
        $graph = $this->graph();
        $store->replace($graph, 'r:old', 'f:old');

        self::assertFalse($store->advanceProvenanceIfUnchanged($graph, 'r:stale', 'f:old', 'r:new', 'f:new'));
        self::assertFalse($store->advanceProvenanceIfUnchanged($graph, 'r:old', 'f:stale', 'r:new', 'f:new'));
        self::assertSame('r:old', $store->sourceRevision());
        self::assertSame('f:old', $store->sourceFingerprint());

        // A different writer won the race by doing a full replace.
        (new GraphStore($this->databaseFile))->replace($graph, 'r:other-writer', 'f:other-writer');
        self::assertFalse($store->advanceProvenanceIfUnchanged($graph, 'r:old', 'f:old', 'r:new', 'f:new'));
        self::assertSame('r:other-writer', $store->sourceRevision());
    }

    public function testRejectsModifiedRemovedAndReorderedRelations(): void
    {
        $store = new GraphStore($this->databaseFile);
        $graph = $this->graph();
        $store->replace($graph, 'r:old', 'f:old');

        $mutated = $graph;
        $mutated[0] = new GraphRelation('r2', 'first', 'calls', ['target-b', 'changed-target']);
        $reorderedTargets = $graph;
        $reorderedTargets[0] = new GraphRelation('r2', 'first', 'calls', ['target-a', 'target-b']);
        $reorderedRelations = [$graph[1], $graph[0], $graph[2]];

        foreach ([$mutated, $reorderedTargets, $reorderedRelations, [$graph[0], $graph[1]], []] as $candidate) {
            self::assertFalse($store->advanceProvenanceIfUnchanged($candidate, 'r:old', 'f:old', 'r:new', 'f:new'));
            self::assertSame('r:old', $store->sourceRevision());
            self::assertSame('f:old', $store->sourceFingerprint());
        }
    }

    public function testMissingLegacyDigestFallsBackToFullRebuild(): void
    {
        $store = new GraphStore($this->databaseFile);
        $graph = $this->graph();
        $store->replace($graph, 'r:a', 'f:a');

        $db = new PDO('sqlite:' . $this->databaseFile);
        $db->exec("DELETE FROM graph_meta WHERE key = 'projection_digest_v1'");

        self::assertFalse($store->advanceProvenanceIfUnchanged($graph, 'r:a', 'f:a', 'r:b', 'f:b'));
        self::assertSame('r:a', $store->sourceRevision());
        $store->replace($graph, 'r:b', 'f:b');
        self::assertTrue($store->advanceProvenanceIfUnchanged($graph, 'r:b', 'f:b', 'r:c', 'f:c'));
    }

    public function testEmptyOrInvalidProvenanceIsRejected(): void
    {
        $store = new GraphStore($this->databaseFile);
        $store->replace($this->graph(), 'r:old', 'f:old');

        $this->expectException(\InvalidArgumentException::class);
        $store->advanceProvenanceIfUnchanged($this->graph(), 'r:old', 'f:old', ' ', 'f:new');
    }

    public function testReadOnlyStoreCannotAdvanceProvenance(): void
    {
        $writable = new GraphStore($this->databaseFile);
        $writable->replace($this->graph(), 'r:old', 'f:old');
        unset($writable);

        $readOnly = GraphStore::openReadOnly($this->databaseFile);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('read-only');
        $readOnly->advanceProvenanceIfUnchanged($this->graph(), 'r:old', 'f:old', 'r:new', 'f:new');
    }

    public function testMetadataUpdateFailureRollsBackBothProvenanceFields(): void
    {
        $store = new GraphStore($this->databaseFile);
        $store->replace($this->graph(), 'r:old', 'f:old');

        $db = new PDO('sqlite:' . $this->databaseFile);
        $db->exec("CREATE TRIGGER reject_fingerprint_update
            BEFORE UPDATE OF value ON graph_meta
            WHEN OLD.key = 'source_fingerprint'
            BEGIN SELECT RAISE(ABORT, 'blocked fingerprint'); END");

        try {
            $store->advanceProvenanceIfUnchanged($this->graph(), 'r:old', 'f:old', 'r:new', 'f:new');
            self::fail('Expected SQLite trigger to block the fingerprint write.');
        } catch (\PDOException $exception) {
            self::assertStringContainsString('blocked fingerprint', $exception->getMessage());
        }

        self::assertSame('r:old', $store->sourceRevision());
        self::assertSame('f:old', $store->sourceFingerprint());
        self::assertSame([], $store->integrityFailures());
    }
}
