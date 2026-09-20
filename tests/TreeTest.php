<?php

declare(strict_types=1);

namespace Onlineconf\Tests;

use Onlineconf\Cdb\CdbWriter;
use Onlineconf\Exception\FormatException;
use Onlineconf\Exception\InvalidJsonException;
use Onlineconf\Module;
use Onlineconf\Source\CdbSource;
use Onlineconf\Tests\Support\RawSource;
use Onlineconf\Tests\Support\TempDir;
use Onlineconf\Tests\Support\TestLogger;
use PHPUnit\Framework\TestCase;

final class TreeTest extends TestCase
{
    private const RAW = [
        '/' => 'j["my"]',
        '/my/' => 'j["service"]',
        '/my/service' => 's1',
        '/my/service/' => 'j["db","empty","timeout"]',
        '/my/service/timeout' => 's30',
        '/my/service/db/' => 'j["host","opts"]',
        '/my/service/db/host' => 'sdb.local',
        '/my/service/db/opts' => 'j{"pool":5}',
    ];

    private const TREE = ['' => '1', 'db' => ['host' => 'db.local', 'opts' => ['pool' => 5]], 'empty' => null, 'timeout' => '30'];

    private TestLogger $logger;

    private RawSource $source;

    private Module $module;

    protected function setUp(): void
    {
        $this->logger = new TestLogger();
        $this->source = new RawSource(self::RAW, 'TREE');
        $this->module = new Module($this->source, $this->logger, 0);
    }

    public function testChildren(): void
    {
        self::assertSame(['my'], $this->module->children('/'));
        self::assertSame(['my'], $this->module->children(''));
        self::assertSame(['service'], $this->module->children('/my'));
        self::assertSame(['db', 'empty', 'timeout'], $this->module->children('/my/service/'));
        self::assertSame([], $this->module->children('/my/service/timeout'), 'a leaf has no child list');
        self::assertSame([], $this->module->children('/nonexistent'));
        self::assertSame([], $this->logger->records, 'child lists exist, no warning for leaves');
    }

    public function testGetTree(): void
    {
        self::assertSame(self::TREE, $this->module->getTree('/my/service'));
        self::assertSame(self::TREE, $this->module->getTree('/my/service/'));
        self::assertSame(['my' => ['service' => self::TREE]], $this->module->getTree('/'));
        self::assertSame(['my' => ['service' => self::TREE]], $this->module->getTree(''));
        self::assertSame('30', $this->module->getTree('/my/service/timeout'));
        self::assertSame(['pool' => 5], $this->module->getTree('/my/service/db/opts'));
        self::assertNull($this->module->getTree('/my/service/empty'));
        self::assertNull($this->module->getTree('/nonexistent'));
    }

    public function testGetTreeMaxDepth(): void
    {
        self::assertSame('1', $this->module->getTree('/my/service', 0), 'depth 0: the node itself as a leaf');
        self::assertSame(['' => '1', 'db' => null, 'empty' => null, 'timeout' => '30'], $this->module->getTree('/my/service', 1));
        self::assertSame(self::TREE, $this->module->getTree('/my/service', 2));
        self::assertSame(self::TREE, $this->module->getTree('/my/service', 99));
        self::assertNull($this->module->getTree('/', 0));
        self::assertSame(['my' => null], $this->module->getTree('/', 1));
    }

    public function testWalk(): void
    {
        $visited = [];
        $this->module->walk('/my/service', static function (string $path, ?string $type, ?string $raw, bool $hasChildren) use (&$visited): void {
            $visited[] = [$path, $type, $raw, $hasChildren];
        });

        self::assertSame([
            ['/my/service', 's', '1', true],
            ['/my/service/db', null, null, true],
            ['/my/service/db/host', 's', 'db.local', false],
            ['/my/service/db/opts', 'j', '{"pool":5}', false],
            ['/my/service/empty', null, null, false],
            ['/my/service/timeout', 's', '30', false],
        ], $visited);

        $visited = [];
        $this->module->walk('/', static function (string $path, ?string $type, ?string $raw, bool $hasChildren) use (&$visited): void {
            $visited[] = [$path, $type, $raw, $hasChildren];
        }, 1);
        self::assertSame([['', null, null, true], ['/my', null, null, false]], $visited, 'the root is reported as ""');
    }

    public function testWalkDoesNotDecodeValues(): void
    {
        $this->source->replace([...self::RAW, '/my/service/' => 'j["broken","cbor"]', '/my/service/broken' => 'j{', '/my/service/cbor' => "c\x01"]);

        $visited = [];
        $this->module->walk('/my/service', static function (string $path, ?string $type, ?string $raw) use (&$visited): void {
            $visited[$path] = [$type, $raw];
        });
        self::assertSame(['j', '{'], $visited['/my/service/broken']);
        self::assertSame(['c', "\x01"], $visited['/my/service/cbor']);
    }

    public function testMissingChildListsWarnOnce(): void
    {
        $module = new Module(new RawSource(['/a' => 's1', '/a/b' => 's2', 'x.y' => 's3'], 'legacy'), $this->logger, 0);

        self::assertSame([], $module->children('/a'));
        self::assertSame([], $module->children('/'));
        self::assertSame([], $module->children('/a/b'));
        self::assertSame('1', $module->getTree('/a'));
        self::assertSame(['warning'], $this->logger->levels());
        self::assertStringContainsString('legacy: child lists are not available', $this->logger->records[0][1]);
    }

    public function testMalformedChildListIsIgnoredWithWarning(): void
    {
        $this->source->replace([...self::RAW, '/my/service/' => 'sdb,timeout', '/my/' => 'j{"a":1}']);

        self::assertSame([], $this->module->children('/my/service'));
        self::assertSame([], $this->module->children('/my'));
        self::assertSame('1', $this->module->getTree('/my/service'));
        self::assertSame(2, $this->logger->count('warning'), 'a malformed child list is reported once per reload');
        self::assertStringContainsString('/my/service/: format is not JSON', $this->logger->messages('warning')[0]);
        self::assertStringContainsString('/my/: JSON value is not an array of strings', $this->logger->messages('warning')[1]);
    }

    public function testBrokenChildListIsCritical(): void
    {
        $this->source->replace([...self::RAW, '/my/service/' => 'j["db",']);

        try {
            $this->module->children('/my/service');
            self::fail('InvalidJsonException expected');
        } catch (InvalidJsonException $e) {
            self::assertSame('TREE:/my/service/: invalid JSON: Syntax error', $e->getMessage());
        }

        self::assertSame(['my'], $this->module->children('/'), 'other child lists are fine');
        $this->expectException(InvalidJsonException::class);
        $this->module->getTree('/');
    }

    public function testBrokenLeafInTreeIsCritical(): void
    {
        $this->source->replace([...self::RAW, '/my/service/db/opts' => 'j{"pool":']);
        self::assertSame(['db', 'empty', 'timeout'], $this->module->children('/my/service'));

        $this->expectException(InvalidJsonException::class);
        $this->expectExceptionMessage('TREE:/my/service/db/opts: invalid JSON: Syntax error');
        $this->module->getTree('/my/service');
    }

    public function testUnknownFormatInTreeIsFormatError(): void
    {
        $this->source->replace([...self::RAW, '/my/service/db/opts' => "c\x01"]);

        $this->expectException(FormatException::class);
        $this->expectExceptionMessage("TREE:/my/service/db/opts: unexpected format 'c'");
        $this->module->getTree('/my/service');
    }

    public function testTreeFromCdb(): void
    {
        $file = TempDir::file();
        CdbWriter::write($file, self::RAW);
        $module = new Module(new CdbSource($file), $this->logger, 0);

        self::assertSame(self::TREE, $module->getTree('/my/service'));
        self::assertSame(['my'], $module->children('/'));
        unlink($file);
    }
}
