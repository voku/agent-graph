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

    public function testReplacementKeepsSecondaryIndexesAndRollsThemBackOnFailure(): void
    {
        $store = new SqliteRelationStore($this->databaseFile);
        $store->replace(new GraphProjection([new GraphRelation('old', 'source', 'calls', ['target'])]));

        try {
            $store->replace(new GraphProjection([
                new GraphRelation('duplicate', 'new', 'calls', ['a']),
                new GraphRelation('duplicate', 'new', 'calls', ['b']),
            ]));
            self::fail('Expected duplicate relation validation failure.');
        } catch (GraphValidationException) {
        }

        $pdo = new \PDO('sqlite:' . $this->databaseFile);
        $indexes = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND name LIKE 'graph_%' ORDER BY name");
        self::assertNotFalse($indexes);
        self::assertSame(
            ['graph_relation_targets_target', 'graph_relations_source_kind'],
            $indexes->fetchAll(\PDO::FETCH_COLUMN),
        );
        self::assertSame(['old'], $this->ids($store->incoming('target')));
    }

    public function testWritableOpenUpgradesVersionOneSchemaInPlace(): void
    {
        $this->createVersionOneDatabase();

        $store = new SqliteRelationStore($this->databaseFile);

        self::assertSame([], $store->integrityFailures());
        self::assertSame(['r2', 'r1'], $this->ids($store->outgoing('source')));
        self::assertSame(['target-b', 'target-a'], $store->outgoing('source', 'calls')[0]->targetIds);
        self::assertSame(['r2', 'r1', 'r3'], $this->ids($store->incoming('target-a')));
        self::assertSame(['r2', 'r1', 'r3'], array_map(static fn (GraphRelation $r): string => $r->id, iterator_to_array($store->relations(), false)));
        self::assertSame(['other', 'source'], $store->neighbourIds('target-a'));
        self::assertSame('legacy-revision', $store->sourceRevision());
        self::assertSame(3, $store->relationCount());

        // The upgraded store keeps working: replacement, indexes and cascade/foreign-key integrity.
        $store->replace(new GraphProjection([new GraphRelation('new', 'a', 'calls', ['b', 'c'])]));
        self::assertSame(['new'], $this->ids($store->incoming('c')));
        self::assertSame([], $store->integrityFailures());

        $pdo = new \PDO('sqlite:' . $this->databaseFile);
        $version = $pdo->query("SELECT value FROM graph_meta WHERE key = 'schema_version'");
        $leftovers = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name LIKE '%\\_v2' ESCAPE '\\'");
        self::assertNotFalse($version);
        self::assertNotFalse($leftovers);
        self::assertSame('2', $version->fetchColumn());
        self::assertSame(0, (int) $leftovers->fetchColumn());

        // Reopening an already upgraded file is a no-op.
        self::assertSame(['new'], $this->ids((new SqliteRelationStore($this->databaseFile))->outgoing('a')));
    }

    public function testReadOnlyOpenOfVersionOneSchemaFailsClosedWithoutChangingTheFile(): void
    {
        $this->createVersionOneDatabase();
        $before = hash_file('sha256', $this->databaseFile);

        try {
            new SqliteRelationStore($this->databaseFile, true);
            self::fail('Expected read-only open of a version 1 schema to fail closed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('schema version is incompatible', $exception->getMessage());
        }

        self::assertSame($before, hash_file('sha256', $this->databaseFile));
    }

    public function testRejectedReadOnlyOpenDoesNotLockOutTheOwnerUpgrade(): void
    {
        $this->createVersionOneDatabase();

        try {
            new SqliteRelationStore($this->databaseFile, true);
            self::fail('Expected read-only open of a version 1 schema to fail closed.');
        } catch (RuntimeException $exception) {
            // The caught exception stays in scope on purpose: the rejected connection must already be released.
            self::assertStringContainsString('schema version is incompatible', $exception->getMessage());
        }

        $upgraded = new SqliteRelationStore($this->databaseFile);

        self::assertNotNull($upgraded->sourceRevision());
    }

    private function createVersionOneDatabase(): void
    {
        $pdo = new \PDO('sqlite:' . $this->databaseFile, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE graph_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE graph_relations (
            relation_id TEXT PRIMARY KEY, relation_position INTEGER NOT NULL UNIQUE,
            source_id TEXT NOT NULL, kind TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE graph_relation_targets (
            relation_id TEXT NOT NULL, target_id TEXT NOT NULL, target_position INTEGER NOT NULL,
            PRIMARY KEY (relation_id, target_position), UNIQUE (relation_id, target_id),
            FOREIGN KEY (relation_id) REFERENCES graph_relations(relation_id) ON DELETE CASCADE)');
        $pdo->exec('CREATE INDEX graph_relations_source_kind ON graph_relations(source_id, kind)');
        $pdo->exec('CREATE INDEX graph_relation_targets_target ON graph_relation_targets(target_id, relation_id)');
        $pdo->exec("INSERT INTO graph_meta VALUES ('schema_version', '1'), ('projection_version', '" . GraphProjection::VERSION . "'),
            ('relation_count', '3'), ('source_revision', 'legacy-revision'), ('source_fingerprint', 'legacy-fingerprint')");
        // Positions deliberately differ from alphabetical id order.
        $pdo->exec("INSERT INTO graph_relations VALUES ('r2', 0, 'source', 'calls'), ('r1', 1, 'source', 'uses'), ('r3', 2, 'other', 'calls')");
        $pdo->exec("INSERT INTO graph_relation_targets VALUES
            ('r2', 'target-b', 0), ('r2', 'target-a', 1), ('r1', 'target-a', 0), ('r3', 'target-a', 0)");
    }

    public function testCachedReadinessIsRevalidatedAfterReplacement(): void
    {
        $store = new SqliteRelationStore($this->databaseFile);

        try {
            $store->incoming('anything');
            self::fail('Expected unpublished store to fail closed.');
        } catch (RuntimeException) {
        }

        $store->replace(new GraphProjection([new GraphRelation('r1', 'a', 'calls', ['b'])]));
        self::assertSame(['r1'], $this->ids($store->incoming('b')));
        self::assertSame(['r1'], $this->ids($store->outgoing('a')));

        $store->replace(new GraphProjection([new GraphRelation('r2', 'a', 'calls', ['c'])]));
        self::assertSame([], $store->incoming('b'));
        self::assertSame(['a'], $store->neighbourIds('c'));
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
