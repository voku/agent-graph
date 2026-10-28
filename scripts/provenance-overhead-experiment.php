#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Disposable owner-side experiment for voku/agent-map#169.
 *
 * Measures graph bulk replacement versus the theoretical lower bound of a
 * provenance-only SQL transaction. The latter is NOT a production API: there
 * is no durable graph-projection digest or cross-process proof that canonical
 * relations are unchanged. DO NOT COPY THIS SQL INTO PRODUCT CODE.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use PDO;
use RuntimeException;
use voku\AgentGraph\Graph\GraphRelation;
use voku\AgentGraph\Sqlite\GraphStore;

$options = getopt('', ['relations::', 'samples::']);
$total = max(100, (int) ($options['relations'] ?? 12000));
$samples = max(3, (int) ($options['samples'] ?? 5));
$directory = rtrim(getenv('RUNNER_TEMP') ?: sys_get_temp_dir(), '/') . '/agent-graph-provenance-' . bin2hex(random_bytes(5));
if (!mkdir($directory, 0o775, true) && !is_dir($directory)) {
    throw new RuntimeException('Cannot create benchmark directory.');
}

/** @return Generator<GraphRelation> */
$generate = static function (int $count, bool $mutate = false): Generator {
    for ($i = 0; $i < $count; ++$i) {
        // The negative fixture changes one ordered target, not just a count.
        $first = $i % 177;
        $second = ($i * 7 + 3) % 177;
        if ($second === $first) {
            $second = ($first + 1) % 177;
        }
        $targets = ['dst' . $first, 'dst' . $second];
        if ($mutate && $i === intdiv($count, 2)) {
            $targets[1] = 'mutated-target';
        }
        yield new GraphRelation('r' . $i, 'source' . ($i % 311), $i % 2 ? 'uses' : 'calls', $targets);
    }
};

/** @param iterable<GraphRelation> $relations */
$digest = static function (iterable $relations): string {
    $context = hash_init('sha256');
    foreach ($relations as $relation) {
        hash_update($context, json_encode($relation->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    }
    return hash_final($context);
};

$expectedDigest = $digest($generate($total));
$mutatedDigest = $digest($generate($total, true));
if (hash_equals($expectedDigest, $mutatedDigest)) {
    throw new RuntimeException('A real target change must invalidate the graph digest.');
}
$reordered = iterator_to_array($generate(3), false);
[$reordered[0], $reordered[1]] = [$reordered[1], $reordered[0]];
if ($digest($reordered) === $digest($generate(3))) {
    throw new RuntimeException('Ordered relation changes must invalidate the graph digest.');
}

$base = $directory . '/base.sqlite';
$initial = new GraphStore($base);
$initial->replace($generate($total), 'revision:0', 'sha256:initial');
if ($initial->relationCount() !== $total || $initial->integrityFailures() !== []) {
    throw new RuntimeException('Fixture baseline is not a valid graph.');
}
unset($initial);
$baseline = GraphStore::openReadOnly($base);
if ($digest($baseline->relations()) !== $expectedDigest) {
    throw new RuntimeException('Baseline SQLite stream lost ordered relations.');
}
unset($baseline);

$fullSamples = [];
$cheapSamples = [];
$scanSamples = [];
for ($i = 0; $i < $samples; ++$i) {
    $fullFile = $directory . '/full-' . $i . '.sqlite';
    $candidateFile = $directory . '/metadata-' . $i . '.sqlite';
    if (!copy($base, $fullFile) || !copy($base, $candidateFile)) {
        throw new RuntimeException('Cannot clone experimental graph.');
    }
    $revision = 'revision:' . ($i + 1);
    $fingerprint = 'sha256:nonrelation-' . ($i + 1);

    $start = hrtime(true);
    $full = new GraphStore($fullFile);
    $full->replace($generate($total), $revision, $fingerprint);
    $fullSamples[] = (hrtime(true) - $start) / 1e6;

    // A full graph verification scan is timed independently. The existing
    // store does not persist the projection digest needed for an O(1) proof.
    $start = hrtime(true);
    $candidate = GraphStore::openReadOnly($candidateFile);
    if (!hash_equals($expectedDigest, $digest($candidate->relations()))) {
        throw new RuntimeException('Graph differs: provenance-only advancement MUST NOT proceed.');
    }
    $scanSamples[] = (hrtime(true) - $start) / 1e6;
    unset($candidate);

    // Theoretical lower bound ONLY, running INSIDE the owner repository.
    // This must never be shipped as consumer SQL or treated as a safe API.
    $pdo = new PDO('sqlite:' . $candidateFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $start = hrtime(true);
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare("UPDATE graph_meta SET value = :next WHERE key = :key AND value = :previous");
        foreach ([
            ['source_revision', 'revision:0', $revision],
            ['source_fingerprint', 'sha256:initial', $fingerprint],
        ] as [$key, $previous, $next]) {
            $query->execute(['key' => $key, 'previous' => $previous, 'next' => $next]);
            if ($query->rowCount() !== 1) {
                throw new RuntimeException('Stale/missing provenance: transaction must refuse advancement.');
            }
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    $cheapSamples[] = (hrtime(true) - $start) / 1e6;
    unset($pdo);

    $fullCheck = GraphStore::openReadOnly($fullFile);
    $cheapCheck = GraphStore::openReadOnly($candidateFile);
    foreach ([$fullCheck, $cheapCheck] as $check) {
        if ($check->sourceRevision() !== $revision || $check->sourceFingerprint() !== $fingerprint) {
            throw new RuntimeException('Provenance did not advance together.');
        }
        if ($check->relationCount() !== $total || $check->integrityFailures() !== []) {
            throw new RuntimeException('Graph is malformed or a relation was lost.');
        }
        if (!hash_equals($expectedDigest, $digest($check->relations()))) {
            throw new RuntimeException('Graph read parity violated.');
        }
        if (serialize($check->outgoing('source7')) !== serialize($fullCheck->outgoing('source7'))
            || serialize($check->incoming('dst7')) !== serialize($fullCheck->incoming('dst7'))) {
            throw new RuntimeException('Indexed read parity violated.');
        }
    }
    unset($full, $fullCheck, $cheapCheck);

    // Expected stale revision must fail an attempted compare-and-swap.
    $pdo = new PDO('sqlite:' . $candidateFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $query = $pdo->prepare("UPDATE graph_meta SET value = :next WHERE key = 'source_revision' AND value = :previous");
    $query->execute(['next' => 'revision:illegal', 'previous' => 'revision:0']);
    if ($query->rowCount() !== 0) {
        throw new RuntimeException('Stale revision was incorrectly accepted.');
    }
    unset($pdo);
}

sort($fullSamples);
sort($cheapSamples);
sort($scanSamples);
$middle = intdiv($samples, 2);
$report = [
    'php' => PHP_VERSION,
    'relations' => $total,
    'samples' => $samples,
    'full_replace_median_ms' => round($fullSamples[$middle], 3),
    'metadata_sql_only_median_ms' => round($cheapSamples[$middle], 3),
    'graph_stream_digest_scan_median_ms' => round($scanSamples[$middle], 3),
    'theoretical_sql_only_speedup' => round($fullSamples[$middle] / max($cheapSamples[$middle], 0.001), 2),
    'checks' => [
        'ordered_relation_stream_same' => true,
        'graph_integrity' => true,
        'incoming_outgoing_parity' => true,
        'stale_revision_rejected' => true,
        'changed_target_digest_rejected' => true,
        'reordering_digest_rejected' => true,
    ],
    'limitations' => [
        'Metadata SQL is experimental owner-side measurement only, not a production API.',
        'The candidate is not safe against concurrent graph mutation between digest scan and SQL transaction.',
        'agent-graph does not persist a graph projection digest to prove unchanged contents in O(1).',
        'The graph-stream scan is a separate, additional cost if performed on every update.',
        'This is not integrated agent-map end-to-end performance or atomic map publication proof.',
    ],
];
$reportFile = $directory . '/result.json';
file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
if (($summary = getenv('GITHUB_STEP_SUMMARY')) !== false && $summary !== '') {
    $markdown = "## agent-graph provenance overhead (research only)\n\n";
    $markdown .= 'PHP ' . PHP_VERSION . '; relations ' . $total . '; samples ' . $samples . ".\n\n";
    $markdown .= "| Operation | Median ms |\n| --- | ---: |\n";
    $markdown .= "| Current full `replace()` | " . $report['full_replace_median_ms'] . " |\n";
    $markdown .= "| Metadata transaction **alone** | " . $report['metadata_sql_only_median_ms'] . " |\n";
    $markdown .= "| Full graph digest scan **separately** | " . $report['graph_stream_digest_scan_median_ms'] . " |\n";
    $markdown .= "\n**NOT A SAFE OPTIMIZATION YET:** No persisted graph-content digest, no concurrent writer protection for the experimental SQL path, and no agent-map publication proof.\n";
    file_put_contents($summary, $markdown, FILE_APPEND);
}
