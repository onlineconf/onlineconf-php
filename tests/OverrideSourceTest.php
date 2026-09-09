<?php

declare(strict_types=1);

namespace Onlineconf\Tests;

use Onlineconf\Module;
use Onlineconf\Source\CdbSource;
use Onlineconf\Source\OverrideSource;
use Onlineconf\Tests\Support\CdbWriter;
use Onlineconf\Tests\Support\RawSource;
use Onlineconf\Tests\Support\TempDir;
use Onlineconf\Tests\Support\TestLogger;
use PHPUnit\Framework\TestCase;

final class OverrideSourceTest extends TestCase
{
    private string $file;

    private OverrideSource $source;

    private Module $module;

    protected function setUp(): void
    {
        $this->file = TempDir::file();
        CdbWriter::writeValues($this->file, ['/svc/timeout' => '30', '/svc/host' => 'db.example.com', '/svc/opts' => ['pool' => 5]]);
        $this->source = new OverrideSource(new CdbSource($this->file));
        $this->module = new Module($this->source, new TestLogger(), 0);
    }

    protected function tearDown(): void
    {
        unlink($this->file);
    }

    public function testWithOverridesOnlyGivenKeysAndRestores(): void
    {
        self::assertSame(30, $this->module->getInt('/svc/timeout', 0));
        $version = $this->module->version();

        $result = $this->source->with(['/svc/timeout' => '1', '/svc/opts' => ['pool' => 1], '/svc/extra' => 'x'], function (): string {
            self::assertSame(1, $this->module->getInt('/svc/timeout', 0), 'cached value was dropped');
            self::assertSame(['pool' => 1], $this->module->getArray('/svc/opts', []));
            self::assertSame('x', $this->module->getString('/svc/extra', ''));
            self::assertSame('db.example.com', $this->module->getString('/svc/host', ''), 'other keys come from the CDB');

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(30, $this->module->getInt('/svc/timeout', 0));
        self::assertSame(['pool' => 5], $this->module->getArray('/svc/opts', []));
        self::assertFalse($this->module->has('/svc/extra'));
        self::assertNotSame($version, $this->module->version());
        self::assertSame(basename($this->file, '.cdb'), $this->module->name());
    }

    public function testNestedWithAndRestoreOnException(): void
    {
        $this->source->with(['/svc/timeout' => '1'], function (): void {
            $this->source->with(['/svc/host' => 'other'], function (): void {
                self::assertSame(1, $this->module->getInt('/svc/timeout', 0));
                self::assertSame('other', $this->module->getString('/svc/host', ''));
            });
            self::assertSame(1, $this->module->getInt('/svc/timeout', 0));
            self::assertSame('db.example.com', $this->module->getString('/svc/host', ''));

            try {
                $this->source->with(['/svc/timeout' => '2'], static function (): void {
                    throw new \LogicException('boom');
                });
                self::fail('exception expected');
            } catch (\LogicException) {
            }
            self::assertSame(1, $this->module->getInt('/svc/timeout', 0), 'restored after the exception');
        });

        self::assertSame(30, $this->module->getInt('/svc/timeout', 0));
    }

    public function testOverrideAndClear(): void
    {
        $this->source->override(['/svc/timeout' => '5']);
        $this->source->override(['/svc/host' => 'h']);
        self::assertSame(5, $this->module->getInt('/svc/timeout', 0));
        self::assertSame('h', $this->module->getString('/svc/host', ''));

        $this->source->clear();
        self::assertSame(30, $this->module->getInt('/svc/timeout', 0));
        self::assertSame('db.example.com', $this->module->getString('/svc/host', ''));
    }

    public function testChildListsAreMerged(): void
    {
        self::assertSame(['host', 'opts', 'timeout'], $this->module->children('/svc'));
        self::assertSame(['svc'], $this->module->children('/'));

        $this->source->override(['/svc/timeout' => '1', '/svc/extra/flag' => '1', '/other' => 'x']);
        self::assertSame(['extra', 'host', 'opts', 'timeout'], $this->module->children('/svc'), 'inner children stay, new ones are added');
        self::assertSame(['other', 'svc'], $this->module->children('/'));
        self::assertSame(['flag'], $this->module->children('/svc/extra'), 'a list unknown to the inner source comes from the overrides');
        self::assertSame(
            ['extra' => ['flag' => '1'], 'host' => 'db.example.com', 'opts' => ['pool' => 5], 'timeout' => '1'],
            $this->module->getTree('/svc'),
        );

        $this->source->clear();
        self::assertSame(['host', 'opts', 'timeout'], $this->module->children('/svc'));
        self::assertSame(['svc'], $this->module->children('/'));
    }

    public function testChildListsAreRestoredAfterWith(): void
    {
        $this->source->with(['/svc/extra' => 'x'], function (): void {
            self::assertSame(['extra', 'host', 'opts', 'timeout'], $this->module->children('/svc'));
        });

        self::assertSame(['host', 'opts', 'timeout'], $this->module->children('/svc'));
    }

    public function testChildListsCannotBePassed(): void
    {
        try {
            $this->source->override(['/svc/' => ['timeout']]);
            self::fail('InvalidArgumentException expected');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('"/svc/" must not be passed', $e->getMessage());
        }
        self::assertSame(['host', 'opts', 'timeout'], $this->module->children('/svc'), 'nothing was overridden');

        $this->expectException(\InvalidArgumentException::class);
        $this->source->with(['/' => 'x'], static fn (): bool => true);
    }

    public function testMalformedInnerChildListIsReplaced(): void
    {
        $source = new OverrideSource(new RawSource(['/svc/' => 'shost,opts', '/svc/host' => 'sh']));
        $source->override(['/svc/extra' => 'x']);

        self::assertSame('j["extra"]', $source->getRaw('/svc/'));
        self::assertSame('sh', $source->getRaw('/svc/host'));
    }

    public function testInnerReloadIsPropagated(): void
    {
        $this->source->override(['/svc/timeout' => '5']);
        self::assertSame(5, $this->module->getInt('/svc/timeout', 0));
        self::assertFalse($this->module->checkForUpdates());

        CdbWriter::writeValues($this->file, ['/svc/timeout' => '60', '/svc/host' => 'new.example.com']);
        self::assertTrue($this->module->checkForUpdates());
        self::assertSame(5, $this->module->getInt('/svc/timeout', 0), 'override still wins');
        self::assertSame('new.example.com', $this->module->getString('/svc/host', ''));
    }
}
