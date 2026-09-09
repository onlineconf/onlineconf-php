<?php

declare(strict_types=1);

namespace Onlineconf\Tests;

use Onlineconf\Exception\OpenException;
use Onlineconf\Module;
use Onlineconf\Source\ArraySource;
use Onlineconf\Source\CdbSource;
use Onlineconf\Tests\Support\CdbWriter;
use Onlineconf\Tests\Support\TempDir;
use Onlineconf\Tests\Support\TestLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SourcesTest extends TestCase
{
    public function testCdbSourceBasics(): void
    {
        $file = TempDir::file('.cdb');
        CdbWriter::write($file, ['/k' => 'sv', '/e' => 's']);
        $source = new CdbSource($file);

        self::assertSame(basename($file, '.cdb'), $source->name());
        self::assertSame('sv', $source->getRaw('/k'));
        self::assertSame('s', $source->getRaw('/e'));
        self::assertNull($source->getRaw('/missing'));
        self::assertFalse($source->reloadIfChanged());
        self::assertMatchesRegularExpression('/^\d+:\d+:\d+$/', $source->version());
        unlink($file);
    }

    public function testCdbSourceMissingFile(): void
    {
        $this->expectException(OpenException::class);
        $this->expectExceptionMessage('/nonexistent/dir/TREE.cdb: cannot open: fopen(');
        new CdbSource('/nonexistent/dir/TREE.cdb');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenFiles(): iterable
    {
        yield 'empty file' => [''];
        yield 'shorter than the header' => ['not a cdb file'];
        yield 'garbage header' => [str_repeat('x', 3000)];
        yield 'pointer beyond the end of file' => [pack('VV', 2048, 1000) . str_repeat("\0", 2040)];
        yield 'pointer into the header' => [pack('VV', 8, 0) . str_repeat("\0", 2040)];
    }

    #[DataProvider('brokenFiles')]
    public function testCdbSourceRejectsBrokenFile(string $content): void
    {
        $file = TempDir::file();
        file_put_contents($file, $content);
        try {
            $this->expectException(OpenException::class);
            $this->expectExceptionMessage($file . ': not a valid CDB file');
            new CdbSource($file);
        } finally {
            unlink($file);
        }
    }

    public function testCdbSourceUnreadableFile(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root can read anything');
        }

        $file = TempDir::file();
        CdbWriter::write($file, ['/k' => 'sv']);
        chmod($file, 0);
        try {
            $this->expectException(OpenException::class);
            $this->expectExceptionMessage($file . ': cannot open: fopen(');
            new CdbSource($file);
        } finally {
            unlink($file);
        }
    }

    public function testCdbSourceDirectory(): void
    {
        $this->expectException(OpenException::class);
        $this->expectExceptionMessage(': not a valid CDB file');
        new CdbSource(sys_get_temp_dir());
    }

    public function testCdbWriterMatchesCdbMake(): void
    {
        if (!in_array('cdb_make', dba_handlers(), true)) {
            self::markTestSkipped('cdb_make handler is not available');
        }

        $raw = ArraySource::rawFromValues(['/a/b' => 'x', '/a/c' => ['k' => 1], '/e' => null]);
        $ours = TempDir::file();
        $theirs = TempDir::file();
        CdbWriter::write($ours, $raw);

        $handle = dba_open($theirs, 'n', 'cdb_make');
        self::assertNotFalse($handle);
        foreach ($raw as $key => $value) {
            dba_insert($key, $value, $handle);
        }
        dba_close($handle);

        self::assertSame(file_get_contents($theirs), file_get_contents($ours), 'pure-PHP writer produces the same bytes as cdb_make');
        unlink($ours);
        unlink($theirs);
    }

    public function testArraySourceFromValues(): void
    {
        $source = ArraySource::fromValues([
            '/a/b' => 'text',
            '/a/c' => ['k' => 1, 'list' => ['x', 'y']],
            '/a/c/d' => 'under a json node',
            '/e' => null,
            '/n' => 5,
            '/f' => 0.5,
            '/t' => true,
            '/z' => false,
            'db.host' => 'legacy',
        ], 'TEST');

        self::assertSame('TEST', $source->name());
        self::assertSame('stext', $source->getRaw('/a/b'));
        self::assertSame('j{"k":1,"list":["x","y"]}', $source->getRaw('/a/c'));
        self::assertSame('s', $source->getRaw('/e'));
        self::assertSame('s5', $source->getRaw('/n'));
        self::assertSame('s0.5', $source->getRaw('/f'));
        self::assertSame('s1', $source->getRaw('/t'));
        self::assertSame('s0', $source->getRaw('/z'));
        self::assertSame('slegacy', $source->getRaw('db.host'));
        self::assertSame('j["a","e","f","n","t","z"]', $source->getRaw('/'));
        self::assertSame('j["b","c"]', $source->getRaw('/a/'));
        self::assertSame('j["d"]', $source->getRaw('/a/c/'));
        self::assertNull($source->getRaw('/a/b/'));
        self::assertNull($source->getRaw('db.host/'));
        self::assertSame('array', (new ArraySource())->name());
    }

    public function testArraySourceGeneratesChildLists(): void
    {
        $source = new ArraySource(['/a/b' => 'sx', '/a/c' => 'j{}', 'db.host' => 'slegacy']);

        self::assertSame('j["a"]', $source->getRaw('/'));
        self::assertSame('j["b","c"]', $source->getRaw('/a/'));
        self::assertNull($source->getRaw('db.host/'), 'no child lists for dot-notation keys');

        $source->replace(['/x/y' => 's1']);
        self::assertSame('j["x"]', $source->getRaw('/'));
        self::assertSame('j["y"]', $source->getRaw('/x/'));
        self::assertNull($source->getRaw('/a/'));
    }

    public function testArraySourceKeepsNumericChildNamesAsStrings(): void
    {
        $source = ArraySource::fromValues(['/shards/0/host' => 'a', '/shards/1/host' => 'b', '/shards/10/host' => 'c']);
        self::assertSame('j["0","1","10"]', $source->getRaw('/shards/'));

        $module = new Module($source, new TestLogger(), 0);
        self::assertSame(['0', '1', '10'], $module->children('/shards'));
        self::assertSame(['0' => ['host' => 'a'], '1' => ['host' => 'b'], '10' => ['host' => 'c']], $module->getTree('/shards'));
    }

    public function testArraySourceRejectsChildLists(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"/a/" must not be passed');
        new ArraySource(['/a/b' => 'sx', '/a/' => 'j["b"]']);
    }

    public function testArraySourceRejectsChildListsInReplace(): void
    {
        $source = new ArraySource(['/a/b' => 'sx']);

        $this->expectException(\InvalidArgumentException::class);
        $source->replace(['/' => 'j["a"]']);
    }
}
