<?php

declare(strict_types=1);

namespace voku\AgentGraph\Sqlite;

use JsonException;

/**
 * Resolves the sqlite-vec loadable extension shipped by agent-graph.
 *
 * sqlite-vec is a SQLite extension rather than a PHP extension, so one binary per platform serves
 * every supported PHP version. Unsupported platforms simply have no bundled vector capability.
 *
 * Bundled binaries are checksum-verified before their path is returned.
 */
final readonly class SqliteVecBinary
{
    /** An explicit path always wins, for an unbundled platform or locally built binary. */
    public const ENVIRONMENT_OVERRIDE = 'AGENT_GRAPH_SQLITE_VEC';

    public static function resolve(): ?string
    {
        $override = getenv(self::ENVIRONMENT_OVERRIDE);
        if (is_string($override) && $override !== '' && is_file($override)) {
            return $override;
        }

        $platform = self::platform();
        if ($platform === null) {
            return null;
        }

        $manifest = self::manifest();
        $binaries = $manifest['binaries'] ?? null;
        if (!is_array($binaries)) {
            return null;
        }

        $entry = $binaries[$platform] ?? null;
        if (!is_array($entry) || !is_string($entry['file'] ?? null) || !is_string($entry['sha256'] ?? null)) {
            return null;
        }

        $path = self::directory() . '/' . $entry['file'];
        if (!is_file($path)) {
            return null;
        }

        $actual = hash_file('sha256', $path);
        if (!is_string($actual) || !hash_equals($entry['sha256'], $actual)) {
            return null;
        }

        return $path;
    }

    public static function version(): ?string
    {
        $version = self::manifest()['version'] ?? null;

        return is_string($version) ? $version : null;
    }

    /**
     * Deliberately coarse: only platforms with explicitly bundled binaries are claimed.
     */
    public static function platform(): ?string
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return null;
        }

        return match (php_uname('m')) {
            'x86_64', 'amd64'  => 'linux-gnu-x86_64',
            'aarch64', 'arm64' => 'linux-gnu-arm64',
            default            => null,
        };
    }

    /** @return array<string, mixed> */
    private static function manifest(): array
    {
        $file = self::directory() . '/manifest.json';
        if (!is_file($file)) {
            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        /** @var array<string, mixed> */
        return is_array($decoded) ? $decoded : [];
    }

    private static function directory(): string
    {
        return dirname(__DIR__, 2) . '/resources/sqlite-vec';
    }
}
