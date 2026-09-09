<?php

declare(strict_types=1);

namespace Onlineconf\Tests;

use Onlineconf\Module;
use Onlineconf\Source\ArraySource;
use Onlineconf\Source\CdbSource;
use Onlineconf\Tests\Support\CdbWriter;
use Onlineconf\Tests\Support\CountingSource;
use Onlineconf\Tests\Support\TempDir;
use Onlineconf\Tests\Support\TestLogger;
use PHPUnit\Framework\TestCase;

final class ReloadTest extends TestCase
{
    private string $file;

    private TestLogger $logger;

    protected function setUp(): void
    {
        $this->file = TempDir::file();
        $this->logger = new TestLogger();
        CdbWriter::writeValues($this->file, ['/k' => 'old', '/j' => ['v' => 1]]);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testCheckIntervalThrottlesStatAndReloadsAfterwards(): void
    {
        $source = new CountingSource(new CdbSource($this->file));
        $module = new Module($source, $this->logger, 1);
        $version = $module->version();

        self::assertSame('old', $module->getString('/k', ''));
        CdbWriter::writeValues($this->file, ['/k' => 'new', '/j' => ['v' => 2]]);
        self::assertSame('old', $module->getString('/k', ''), 'before the interval expires the old data is served');
        self::assertSame(['v' => 1], $module->getArray('/j', []));
        self::assertSame(0, $source->reloadIfChanged, 'no stat right after opening');

        usleep(1_100_000);
        self::assertSame('new', $module->getString('/k', ''));
        self::assertSame(['v' => 2], $module->getArray('/j', []), 'decoded cache was dropped');
        self::assertSame(1, $source->reloadIfChanged);
        self::assertNotSame($version, $module->version());
        self::assertSame(['info'], $this->logger->levels());
        self::assertStringContainsString('reloaded, version ' . $module->version(), $this->logger->records[0][1]);

        for ($i = 0; $i < 1000; ++$i) {
            $module->getString('/k', '');
        }
        self::assertSame(1, $source->reloadIfChanged, 'at most one check per interval');
        self::assertSame(4, $source->getRaw, 'two keys read before the reload and two after it, nothing else');
    }

    public function testCheckIntervalZeroChecksOnEveryAccess(): void
    {
        $source = new CountingSource(new CdbSource($this->file));
        $module = new Module($source, $this->logger, 0);

        self::assertSame('old', $module->getString('/k', ''));
        CdbWriter::writeValues($this->file, ['/k' => 'new']);
        self::assertSame('new', $module->getString('/k', ''));
        self::assertSame(2, $source->reloadIfChanged);
    }

    public function testCheckForUpdatesBypassesThrottling(): void
    {
        $source = new CountingSource(new CdbSource($this->file));
        $module = new Module($source, $this->logger, 3600);
        $version = $module->version();

        self::assertSame('old', $module->getString('/k', ''));
        self::assertFalse($module->checkForUpdates());
        self::assertSame($version, $module->version());

        CdbWriter::writeValues($this->file, ['/k' => 'new']);
        self::assertSame('old', $module->getString('/k', ''));
        self::assertTrue($module->checkForUpdates());
        self::assertNotSame($version, $module->version());
        self::assertSame('new', $module->getString('/k', ''));
        self::assertFalse($module->has('/j'));
        self::assertSame(2, $source->reloadIfChanged);
    }

    public function testSameContentRewrittenIsDetectedByInode(): void
    {
        $source = new CdbSource($this->file);
        $module = new Module($source, $this->logger, 0);
        $version = $module->version();
        [$inode, $mtime, $size] = explode(':', $version);
        self::assertSame((string) fileinode($this->file), $inode);
        self::assertSame((string) filemtime($this->file), $mtime);
        self::assertSame((string) filesize($this->file), $size);

        CdbWriter::writeValues($this->file, ['/k' => 'old', '/j' => ['v' => 1]]);
        self::assertTrue($module->checkForUpdates());
        self::assertNotSame($version, $module->version());
    }

    public function testBrokenReplacementKeepsOldDataAndLogsError(): void
    {
        $module = new Module(new CdbSource($this->file), $this->logger, 0);
        self::assertSame('old', $module->getString('/k', ''));

        file_put_contents($this->file . '.tmp', 'definitely not a cdb');
        rename($this->file . '.tmp', $this->file);
        $version = $module->version();

        self::assertFalse($module->checkForUpdates());
        self::assertSame('old', $module->getString('/k', ''));
        self::assertSame($version, $module->version());
        self::assertSame(['error', 'error'], $this->logger->levels(), 'every failed attempt is logged (interval 0: one per access)');
        self::assertStringContainsString('reload failed, keeping old data', $this->logger->records[0][1]);

        // a correct file arrives later: picked up at the next check
        CdbWriter::writeValues($this->file, ['/k' => 'fixed']);
        self::assertTrue($module->checkForUpdates());
        self::assertSame('fixed', $module->getString('/k', ''));
    }

    public function testDeletedFileKeepsOldData(): void
    {
        $module = new Module(new CdbSource($this->file), $this->logger, 0);
        unlink($this->file);

        self::assertSame('old', $module->getString('/k', ''));
        self::assertSame(1, $this->logger->count('error'));
        self::assertStringContainsString('cannot stat', $this->logger->records[0][1]);
    }

    public function testInvalidJsonIsRetriedAfterReload(): void
    {
        CdbWriter::write($this->file, ['/j' => 'j{"v":']);
        $module = new Module(new CdbSource($this->file), $this->logger, 0);

        try {
            $module->getArray('/j', []);
            self::fail('InvalidJsonException expected');
        } catch (\Onlineconf\Exception\InvalidJsonException) {
        }

        CdbWriter::writeValues($this->file, ['/j' => ['v' => 1]]);
        self::assertSame(['v' => 1], $module->getArray('/j', []));
    }

    public function testArraySourceReplace(): void
    {
        $source = new ArraySource(['/k' => 'sold']);
        $module = new Module($source, $this->logger, 0);
        self::assertSame('0', $module->version());
        self::assertSame('old', $module->getString('/k', ''));
        self::assertFalse($module->checkForUpdates());

        $source->replaceValues(['/k' => 'new']);
        self::assertSame('1', $module->version());
        self::assertSame('new', $module->getString('/k', ''));

        $source->replace(['/k' => 'snewer']);
        self::assertTrue($module->checkForUpdates());
        self::assertFalse($module->checkForUpdates());
        self::assertSame('newer', $module->getString('/k', ''));
        self::assertSame('2', $module->version());
    }
}
