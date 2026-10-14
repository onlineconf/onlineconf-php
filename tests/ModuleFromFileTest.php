<?php

declare(strict_types=1);

namespace Onlineconf\Tests;

use Onlineconf\Cdb\CdbWriter;
use Onlineconf\Exception\NotFoundException;
use Onlineconf\Exception\OpenException;
use Onlineconf\Module;
use Onlineconf\Settings;
use Onlineconf\Tests\Support\TempDir;
use Onlineconf\Tests\Support\TestLogger;
use PHPUnit\Framework\TestCase;

/**
 * Module::fromFile(): a required module file must be there; an optional one may appear later.
 * Module::fromSettings(): the same by the settings, or an empty module that touches no file for a disabled client.
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

    public function testFromSettingsOpensTheFileInTheirMode(): void
    {
        $dir = dirname($this->file);
        $name = basename($this->file, '.cdb');

        try {
            Module::fromSettings(new Settings($dir, $name, true), null, $this->logger, 0);
            self::fail('OpenException expected for a missing required file');
        } catch (OpenException $e) {
            self::assertStringContainsString('set ONLINECONF_REQUIRED=false', $e->getMessage());
        }

        $optional = Module::fromSettings(new Settings($dir, $name, false), null, $this->logger, 0);
        self::assertSame('missing', $optional->version());

        CdbWriter::writeValues($this->file, ['/k' => 'v']);
        $byPath = Module::fromSettings(new Settings('/elsewhere', 'TREE'), $this->file, $this->logger, 0);
        self::assertSame('v', $byPath->getString('/k'), 'a name with "/" is a path, as for fileName()');
    }

    public function testADisabledClientIsAnEmptyModuleThatTouchesNoFile(): void
    {
        CdbWriter::writeValues($this->file, ['/k' => 'v']);
        $settings = new Settings(dirname($this->file), basename($this->file, '.cdb'), true, false);

        $module = Module::fromSettings($settings, null, $this->logger, 0);

        self::assertSame('disabled', $module->version());
        self::assertSame(basename($this->file, '.cdb'), $module->name());
        self::assertFalse($module->has('/k'), 'the file is there, but a disabled client does not open it');
        self::assertSame(80, $module->getInt('/port', '80'), 'get* give their parsed default');
        self::assertFalse($module->checkForUpdates(), 'and never reloads');
        self::assertSame([], $module->children('/'), 'no tree, and no advice about child lists');
        self::assertSame([], $this->logger->records, 'a disabled client is not worth a log line');

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('/k: key not found: OnlineConf is disabled (ONLINECONF_ENABLED=false)');
        $module->requireString('/k');
    }

    public function testADisabledClientOpensNoMissingRequiredFile(): void
    {
        $module = Module::fromSettings(new Settings('/nowhere', 'TREE', true, false), null, $this->logger, 0);

        self::assertSame('disabled', $module->version(), 'required applies to a client that reads files');
        self::assertSame('TREE', $module->name());
    }
}
