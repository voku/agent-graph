#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use voku\AgentGraph\Graph\GraphRelation;
use voku\AgentGraph\Sqlite\GraphStore;

$total = 12000;
$samples = 5;
$root = rtrim((string) (getenv('RUNNER_TEMP') ?: sys_get_temp_dir()), '/') . '/agent-graph-guarded-' . bin2hex(random_bytes(5));
mkdir($root, 0o775, true);

/** @return Generator<GraphRelation> */
$relations = static function () use ($total): Generator {
    for ($i = 0; $i < $total; ++$i) {
        $a = $i % 211;
        $b = ($a + 1 + $i % 200) % 211;
        yield new GraphRelation('r' . $i, 's' . ($i % 311), $i % 2 ? 'uses' : 'calls', ['t' . $a, 't' . $b]);
    }
};

$basePath = $root . '/base.sqlite';
$base = new GraphStore($basePath);
$base->replace($relations(), 'revision:old', 'fingerprint:old');
unset($base);

$fullTimes = [];
$guardedTimes = [];
for ($i = 0; $i < $samples; ++$i) {
    $fullPath = $root . '/full-' . $i . '.sqlite';
    $guardedPath = $root . '/guarded-' . $i . '.sqlite';
    if (!copy($basePath, $fullPath) || !copy($basePath, $guardedPath)) {
        throw new RuntimeException('Unable to clone graph fixture.');
    }

    $newRevision = 'revision:' . $i;
    $newFingerprint = 'fingerprint:' . $i;
    $full = new GraphStore($fullPath);
    $guarded = new GraphStore($guardedPath);

    if ($i % 2 === 0) {
        $start = hrtime(true);
        $full->replace($relations(), $newRevision, $newFingerprint);
        $fullTimes[] = (hrtime(true) - $start) / 1e6;

        $start = hrtime(true);
        $accepted = $guarded->advanceProvenanceIfUnchanged($relations(), 'revision:old', 'fingerprint:old', $newRevision, $newFingerprint);
        $guardedTimes[] = (hrtime(true) - $start) / 1e6;
    } else {
        $start = hrtime(true);
        $accepted = $guarded->advanceProvenanceIfUnchanged($relations(), 'revision:old', 'fingerprint:old', $newRevision, $newFingerprint);
        $guardedTimes[] = (hrtime(true) - $start) / 1e6;

        $start = hrtime(true);
        $full->replace($relations(), $newRevision, $newFingerprint);
        $fullTimes[] = (hrtime(true) - $start) / 1e6;
    }

    if (!$accepted || $guarded->sourceRevision() !== $full->sourceRevision()
        || $guarded->sourceFingerprint() !== $full->sourceFingerprint()
        || $guarded->relationCount() !== $total
        || $guarded->integrityFailures() !== []
        || serialize(iterator_to_array($full->relations(), false)) !== serialize(iterator_to_array($guarded->relations(), false))
        || serialize($full->incoming('t5')) !== serialize($guarded->incoming('t5'))
        || serialize($full->outgoing('s5')) !== serialize($guarded->outgoing('s5'))) {
        throw new RuntimeException('Guarded operation diverged from full replacement.');
    }

    if ($guarded->advanceProvenanceIfUnchanged($relations(), 'revision:old', 'fingerprint:old', 'revision:bad', 'fingerprint:bad')) {
        throw new RuntimeException('Reusing the old provenance must fail.');
    }
}
sort($fullTimes);
sort($guardedTimes);
$medianIndex = intdiv($samples, 2);
$fullMedian = round($fullTimes[$medianIndex], 3);
$guardedMedian = round($guardedTimes[$medianIndex], 3);
$report = [
    'php' => PHP_VERSION,
    'relations' => $total,
    'samples' => $samples,
    'full_rebuild_median_ms' => $fullMedian,
    'guarded_advance_median_ms' => $guardedMedian,
    'measured_speedup' => round($fullMedian / max($guardedMedian, 0.001), 2),
    'full_graph_query_and_provenance_parity' => true,
    'old_provenance_rejected' => true,
    'limitations' => [
        'Synthetic graph-only workload; not an agent-map end-to-end refresh.',
        'Candidate digest hashing is included in the guarded duration.',
        'No source-file PHPStan analysis or canonical serialization included.',
    ],
];
file_put_contents($root . '/result.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
if (($summary = getenv('GITHUB_STEP_SUMMARY')) !== false && $summary !== '') {
    file_put_contents($summary, sprintf(
        "## Guarded provenance replacement\n\nPHP %s, %d relations, %d A/B runs.\n\n- Full replacement median: **%.3f ms**\n- Guarded provenance median: **%.3f ms**\n- Measured graph-only speedup: **%.2fx**\n- Full query/provenance parity: **PASS**\n\n*Not an agent-map end-to-end speedup.*\n",
        PHP_VERSION, $total, $samples, $fullMedian, $guardedMedian, $report['measured_speedup'],
    ), FILE_APPEND);
}
