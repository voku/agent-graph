#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Read/write benchmark for GraphStore on a deterministic synthetic graph.
 *
 * Usage: php scripts/benchmark.php [--relations=200000] [--nodes=40000] [--runs=30]
 *                                   [--database=/path/graph.sqlite] [--rebuild] [--json]
 *
 * The database is generated once and reused (keyed by relations/nodes) unless --rebuild is given.
 * Compare two revisions by running this on each (git stash / git worktree) and diffing the output;
 * the "checksum" lines must be identical or the optimisation changed behaviour.
 * Timings are medians in milliseconds; expect noise on shared machines, so compare several runs.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use voku\AgentGraph\Graph\GraphRelation;
use voku\AgentGraph\Graph\TraversalDirection;
use voku\AgentGraph\Sqlite\GraphStore;

$options = getopt('', ['relations::', 'nodes::', 'runs::', 'database::', 'rebuild', 'json']);
$relationTotal = (int) ($options['relations'] ?? 200000);
$nodeTotal = (int) ($options['nodes'] ?? 40000);
$runs = max(3, (int) ($options['runs'] ?? 30));
$json = isset($options['json']);
$database = is_string($options['database'] ?? null)
    ? $options['database']
    : sys_get_temp_dir() . "/agent-graph-bench-{$relationTotal}-{$nodeTotal}.sqlite";

$hub = 'n5';
$mid = 'n' . intdiv($nodeTotal, 3);

$generate = static function () use ($relationTotal, $nodeTotal): Generator {
    mt_srand(1);
    for ($i = 0; $i < $relationTotal; ++$i) {
        $targets = [];
        for ($j = mt_rand(1, 3); $j > 0; --$j) {
            // ~20% of targets land on 21 hub nodes to model high fan-in.
            $targets['n' . (mt_rand(0, 9) < 2 ? mt_rand(0, 20) : mt_rand(0, $nodeTotal))] = true;
        }
        yield new GraphRelation("r{$i}", 'n' . mt_rand(0, $nodeTotal), $i % 2 === 0 ? 'calls' : 'uses', array_keys($targets));
    }
};

$results = [];
$record = static function (string $label, float $ms) use (&$results, $json): void {
    $results[$label] = round($ms, 3);
    if (!$json) {
        printf("%-28s %10.3f ms\n", $label, $ms);
    }
};
$median = static function (callable $work) use ($runs): float {
    $work();
    $samples = [];
    for ($i = 0; $i < $runs; ++$i) {
        $start = hrtime(true);
        $work();
        $samples[] = (hrtime(true) - $start) / 1e6;
    }
    sort($samples);

    return $samples[intdiv($runs, 2)];
};

if (isset($options['rebuild']) || !is_file($database)) {
    foreach ([$database, $database . '-wal', $database . '-shm'] as $file) {
        is_file($file) && unlink($file);
    }
    $start = hrtime(true);
    (new GraphStore($database))->replace($generate(), 'bench', 'bench');
    $record('rebuild (full replace)', (hrtime(true) - $start) / 1e6);
}

$start = hrtime(true);
$graph = GraphStore::openReadOnly($database);
$graph->incoming($mid);
$record('open + first query', (hrtime(true) - $start) / 1e6);

$record('incoming hub', $median(static fn () => $graph->incoming($hub)));
$record('incoming mid', $median(static fn () => $graph->incoming($mid)));
$record('outgoing mid', $median(static fn () => $graph->outgoing($mid)));
$record('outgoing mid kind', $median(static fn () => $graph->outgoing($mid, 'calls')));
$record('neighbours hub', $median(static fn () => $graph->neighbours($hub)));
$record('neighbours mid', $median(static fn () => $graph->neighbours($mid)));
$record('traverse in d2 (hub)', $median(static fn () => $graph->traverse($hub, TraversalDirection::INCOMING, 2, 100)));
if (str_contains((string) (new ReflectionMethod(GraphStore::class, 'traverse')), 'maximumRelations')) {
    $record('traverse in d2 hub cap=200', $median(static fn () => $graph->traverse($hub, TraversalDirection::INCOMING, 2, 100, maximumRelations: 200)));
}
$record('traverse in d2 (mid)', $median(static fn () => $graph->traverse($mid, TraversalDirection::INCOMING, 2, 100)));
$record('traverse out d3', $median(static fn () => $graph->traverse($mid, TraversalDirection::OUTGOING, 3, 100)));

$checksums = [
    'incoming hub' => md5(serialize($graph->incoming($hub))),
    'outgoing mid' => md5(serialize($graph->outgoing($mid))),
    'neighbours hub' => md5(serialize($graph->neighbours($hub))),
    'traverse in hub' => md5(serialize($graph->traverse($hub, TraversalDirection::INCOMING, 2, 100))),
    'traverse out mid' => md5(serialize($graph->traverse($mid, TraversalDirection::OUTGOING, 3, 100))),
];

if ($json) {
    echo json_encode(['timings_ms' => $results, 'checksums' => $checksums], JSON_PRETTY_PRINT), "\n";

    return;
}
foreach ($checksums as $label => $sum) {
    printf("checksum %-19s %s\n", $label, $sum);
}
