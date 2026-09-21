<?php

declare(strict_types=1);

namespace Onlineconf\Tests;

use Onlineconf\Cdb\CdbWriter;
use Onlineconf\Exception\OpenException;
use Onlineconf\Onlineconf;
use Onlineconf\Tests\Support\TempDir;
use Onlineconf\Tests\Support\TestLogger;
use PHPUnit\Framework\TestCase;

final class RegistryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('oc-registry');
        CdbWriter::writeValues($this->dir . '/TREE.cdb', ['/k' => 'tree']);
        CdbWriter::writeValues($this->dir . '/other.cdb', ['/k' => 'other']);
        Onlineconf::reset();
        Onlineconf::setDefaultDir($this->dir);
    }

    protected function tearDown(): void
    {
        Onlineconf::reset();
        TempDir::remove($this->dir);
    }

    public function testSameFileGivesSameModule(): void
    {
        $module = Onlineconf::module();
        self::assertSame('tree', $module->getString('/k', ''));
        self::assertSame('TREE', $module->name());

        self::assertSame($module, Onlineconf::module('TREE'));
        self::assertSame($module, Onlineconf::module($this->dir . '/TREE.cdb'));
        self::assertSame($module, Onlineconf::module($this->dir . '/TREE'));
        self::assertSame($module, Onlineconf::module($this->dir . '/../' . basename($this->dir) . '//TREE.cdb'));

        $other = Onlineconf::module('other');
        self::assertNotSame($module, $other);
        self::assertSame('other', $other->getString('/k', ''));
    }

    public function testSettersApplyBeforeOpening(): void
    {
        $logger = new TestLogger();
        Onlineconf::setLogger($logger);
        Onlineconf::setDefaultModule('other');
        Onlineconf::setCheckInterval(0);

        $module = Onlineconf::module();
        self::assertSame('other', $module->getString('/k', ''));
        self::assertSame($this->dir, Onlineconf::settings()->dir);
        self::assertSame('other', Onlineconf::settings()->module);

        unlink($this->dir . '/other.cdb');
        self::assertSame('other', $module->getString('/k', ''), 'old data is kept');
        self::assertSame(['error'], $logger->levels(), 'the injected logger is used; interval 0 checks at once');
    }

    public function testFailedOpenDoesNotPoisonRegistry(): void
    {
        try {
            Onlineconf::module('missing');
            self::fail('OpenException expected');
        } catch (OpenException $e) {
            self::assertStringContainsString($this->dir . '/missing.cdb: cannot open', $e->getMessage());
        }

        CdbWriter::writeValues($this->dir . '/missing.cdb', ['/k' => 'now here']);
        self::assertSame('now here', Onlineconf::module('missing')->getString('/k', ''));
    }

    public function testResetForgetsModules(): void
    {
        $module = Onlineconf::module();
        Onlineconf::reset();
        Onlineconf::setDefaultDir($this->dir);
        self::assertNotSame($module, Onlineconf::module());
    }
}
