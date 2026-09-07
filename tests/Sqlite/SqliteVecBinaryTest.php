<?php

declare(strict_types=1);

namespace voku\AgentGraph\Tests\Sqlite;

use PHPUnit\Framework\TestCase;
use voku\AgentGraph\Sqlite\SqliteVecBinary;

final class SqliteVecBinaryTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv(SqliteVecBinary::ENVIRONMENT_OVERRIDE);
    }

    public function testManifestVersionIsExposed(): void
    {
        $manifestFile = dirname(__DIR__, 2) . '/resources/sqlite-vec/manifest.json';
        $contents = file_get_contents($manifestFile);
        if (!is_string($contents)) {
            self::fail('Unable to read sqlite-vec manifest.');
        }

        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) {
            self::fail('sqlite-vec manifest must decode to an object.');
        }

        $version = $manifest['version'] ?? null;
        if (!is_string($version)) {
            self::fail('sqlite-vec manifest must contain a string version.');
        }

        self::assertMatchesRegularExpression('/^v\d+\.\d+\.\d+(?:[.-][0-9A-Za-z.-]+)?$/', $version);
        self::assertSame($version, SqliteVecBinary::version());
    }

    public function testBundledBinaryResolvesOnSupportedPlatform(): void
    {
        $platform = SqliteVecBinary::platform();
        if ($platform === null) {
            self::markTestSkipped('No bundled sqlite-vec binary for this platform.');
        }

        $binary = SqliteVecBinary::resolve();

        self::assertNotNull($binary);
        self::assertFileExists($binary);
        self::assertSame('vec0.so', basename($binary));
        self::assertStringContainsString('/resources/sqlite-vec/' . $platform . '/', str_replace('\\', '/', $binary));
    }

    public function testExplicitExistingOverrideWins(): void
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'agent-graph-sqlite-vec-');
        if ($temporaryFile === false) {
            self::fail('Unable to create temporary sqlite-vec override file.');
        }

        try {
            self::assertTrue(putenv(SqliteVecBinary::ENVIRONMENT_OVERRIDE . '=' . $temporaryFile));
            self::assertSame($temporaryFile, SqliteVecBinary::resolve());
        } finally {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }
    }
}
