<?php

declare(strict_types=1);

namespace Onlineconf\Tests;

use Onlineconf\Cdb\CdbWriter;
use Onlineconf\Exception\NotFoundException;
use Onlineconf\Exception\OpenException;
use Onlineconf\Module;
use Onlineconf\Tests\Support\TempDir;
use Onlineconf\Tests\Support\TestLogger;
use PHPUnit\Framework\TestCase;

/**
 * Module::fromFile(): a required module file must be there; an optional one may appear later.
 */
final class ModuleFromFileTest extends TestCase
{
    private string $file;

    private TestLogger $logger;

    protected function setUp(): void
    {
        $this->file = TempDir::file('.cdb');
        $this->logger = new TestLogger();
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testAnExistingFileIsOpenedInEitherMode(): void
    {
        CdbWriter::writeValues($this->file, ['/k' => 'v']);

        self::assertSame('v', Module::fromFile($this->file, true, $this->logger, 0)->getString('/k'));
        self::assertSame('v', Module::fromFile($this->file, false, $this->logger, 0)->getString('/k'));
    }

    public function testAMissingRequiredFileSaysHowToStartWithoutIt(): void
    {
        $this->expectException(OpenException::class);
        $this->expectExceptionMessage($this->file . ': no such file; set ONLINECONF_REQUIRED=false to start without it');

        Module::fromFile($this->file, true, $this->logger, 0);
    }

    public function testRequiredIsTheDefault(): void
    {
        $this->expectException(OpenException::class);

        Module::fromFile($this->file);
    }

    public function testAMissingOptionalFileIsAnEmptyModule(): void
    {
        $module = Module::fromFile($this->file, false, $this->logger, 0);

        self::assertSame('missing', $module->version());
        self::assertSame(basename($this->file, '.cdb'), $module->name());
        self::assertFalse($module->has('/k'));
        self::assertSame(80, $module->getInt('/port', '80'), 'get* give their parsed default');
        self::assertNull($module->getString('/k'));
        self::assertSame([], $this->logger->records, 'a missing optional module is not worth a log line');

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('/k: key not found: module file ' . $this->file . ' is missing');
        $module->requireString('/k');
    }

    public function testAFileThatAppearsLaterIsPickedUp(): void
    {
        $module = Module::fromFile($this->file, false, $this->logger, 0);
        self::assertNull($module->getString('/k'));

        CdbWriter::writeValues($this->file, ['/k' => 'delivered']);

        self::assertSame('delivered', $module->getString('/k'), 'the next check opens it, without a restart');
        self::assertNotSame('missing', $module->version());

        CdbWriter::writeValues($this->file, ['/k' => 'updated']);
        self::assertSame('updated', $module->getString('/k'), 'and it reloads as any module does');
    }

    public function testTheCheckIntervalAppliesWhileTheFileIsMissing(): void
    {
        $module = Module::fromFile($this->file, false, $this->logger, 3600);
        CdbWriter::writeValues($this->file, ['/k' => 'delivered']);

        self::assertNull($module->getString('/k'), 'not checked again within the interval');
        self::assertTrue($module->checkForUpdates());
        self::assertSame('delivered', $module->getString('/k'));
    }

    public function testABrokenFileIsAnErrorInEitherMode(): void
    {
        file_put_contents($this->file, "not a cdb file\n");

        $this->expectException(OpenException::class);
        Module::fromFile($this->file, false, $this->logger, 0);
    }

    public function testABrokenFileThatAppearsLaterIsRetried(): void
    {
        $module = Module::fromFile($this->file, false, $this->logger, 0);
        file_put_contents($this->file, "not a cdb file\n");

        self::assertNull($module->getString('/k'), 'kept empty');
        self::assertSame(['error'], $this->logger->levels(), 'the reload failure is logged as for any module');

        CdbWriter::writeValues($this->file, ['/k' => 'fixed']);
        self::assertSame('fixed', $module->getString('/k'));
    }
}
