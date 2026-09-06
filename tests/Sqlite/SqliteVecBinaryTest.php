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

    public function testPinnedVersionIsExposed(): void
    {
        self::assertSame('v0.1.7-alpha.2', SqliteVecBinary::version());
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
