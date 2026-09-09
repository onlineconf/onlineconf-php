<?php

declare(strict_types=1);

namespace Onlineconf\Tests;

use Onlineconf\Exception\NotFoundException;
use Onlineconf\Module;
use Onlineconf\Source\ArraySource;
use Onlineconf\Subtree;
use Onlineconf\Tests\Support\TestLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SubtreeTest extends TestCase
{
    private Module $module;

    protected function setUp(): void
    {
        $this->module = new Module(ArraySource::fromValues([
            '/my/service' => '1',
            '/my/service/timeout' => '30',
            '/my/service/ratio' => '0.25',
            '/my/service/delay' => '1.5s',
            '/my/service/tags' => 'a,b',
            '/my/service/db/host' => 'db.local',
            '/my/service/db/opts' => ['pool' => 5],
            '/other' => 'x',
        ]), new TestLogger(), 0);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function prefixes(): iterable
    {
        yield 'plain' => ['/my/service', '/my/service'];
        yield 'trailing slash' => ['/my/service/', '/my/service'];
        yield 'double slash' => ['//my//service', '/my/service'];
        yield 'dot segments' => ['/my/./service/.', '/my/service'];
        yield 'dot-dot' => ['/my/x/../service', '/my/service'];
        yield 'dot-dot above root' => ['/../my/service', '/my/service'];
        yield 'root' => ['/', ''];
        yield 'empty' => ['', ''];
        yield 'dot' => ['.', ''];
        yield 'relative' => ['my/service', 'my/service'];
        yield 'relative dot-dot kept' => ['../my', '../my'];
        yield 'relative dot-dot resolved' => ['a/../my', 'my'];
    }

    #[DataProvider('prefixes')]
    public function testCleanPrefix(string $prefix, string $expected): void
    {
        self::assertSame($expected, Subtree::cleanPrefix($prefix));
        self::assertSame($expected . '/x', $this->module->subtree($prefix)->path('/x'));
    }

    public function testGettersConcatenatePaths(): void
    {
        $svc = $this->module->subtree('/my/service/');

        self::assertTrue($svc->has('/timeout'));
        self::assertSame('s30', $svc->getRaw('/timeout'));
        self::assertNull($svc->getRaw('/missing'));
        self::assertFalse($svc->has('/missing'));
        self::assertSame('30', $svc->getString('/timeout', ''));
        self::assertSame(30, $svc->getInt('/timeout', 0));
        self::assertSame(0.25, $svc->getFloat('/ratio', 0.0));
        self::assertTrue($svc->getBool('', false), 'empty path reads the node itself');
        self::assertSame(1.5, $svc->getDuration('/delay', 0.0));
        self::assertSame(1500, $svc->getDurationMs('/delay', 0));
        self::assertSame(['a', 'b'], $svc->getStrings('/tags', []));
        self::assertSame(['pool' => 5], $svc->getArray('/db/opts', []));
        self::assertSame('db.local', $svc->get('/db/host', null));
        self::assertSame('db.local', $svc->require('/db/host'));

        self::assertSame('30', $svc->requireString('/timeout'));
        self::assertSame(30, $svc->requireInt('/timeout'));
        self::assertSame(0.25, $svc->requireFloat('/ratio'));
        self::assertTrue($svc->requireBool(''));
        self::assertSame(1.5, $svc->requireDuration('/delay'));
        self::assertSame(1500, $svc->requireDurationMs('/delay'));
        self::assertSame(['a', 'b'], $svc->requireStrings('/tags'));
        self::assertSame(['pool' => 5], $svc->requireArray('/db/opts'));

        self::assertSame(['db', 'delay', 'ratio', 'tags', 'timeout'], $svc->children(''));
        self::assertSame(['host', 'opts'], $svc->children('/db'));
        self::assertSame(['host' => 'db.local', 'opts' => ['pool' => 5]], $svc->getTree('/db'));

        $visited = [];
        $svc->walk('/db', static function (string $path) use (&$visited): void {
            $visited[] = $path;
        }, 1);
        self::assertSame(['/my/service/db', '/my/service/db/host', '/my/service/db/opts'], $visited);

        $this->expectException(NotFoundException::class);
        $svc->requireInt('/missing');
    }

    public function testNestedSubtrees(): void
    {
        $svc = $this->module->subtree('/my/service');
        self::assertSame('/my/service/db/host', $svc->subtree('/db')->path('/host'));
        self::assertSame('/my/service/db/host', $svc->subtree('db/')->path('/host'));
        self::assertSame('/my/service/db/host', $svc->subtree('/x/../db')->path('/host'));
        self::assertSame('db.local', $svc->subtree('/db')->getString('/host', ''));
        self::assertSame('/my/service/x', $svc->subtree('/')->path('/x'));
        self::assertSame('/my/service/x', $svc->subtree('')->path('/x'));
        self::assertSame('/my/x', $svc->subtree('..')->path('/x'));

        $root = $this->module->subtree('/');
        self::assertSame('/other', $root->subtree('/')->path('/other'));
        self::assertSame('x', $root->getString('/other', ''));
        self::assertSame('other', $root->subtree('other')->path(''), 'a relative prefix stays relative');
    }
}
