<?php

declare(strict_types=1);

namespace Onlineconf\Tests;

use Onlineconf\Cdb\CdbReader;
use Onlineconf\Cdb\CdbWriter;
use Onlineconf\Exception\OpenException;
use Onlineconf\Source\ArraySource;
use Onlineconf\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class CdbReaderTest extends TestCase
{
    public function testReadReturnsEveryPairWrittenIncludingChildLists(): void
    {
        $raw = ArraySource::rawFromValues(['/app/name' => 'demo', '/app/opts' => ['pool' => 5], '/app/empty' => null, 'dotted.key' => '1']);
        $file = TempDir::file('.cdb');
        CdbWriter::write($file, $raw);

        $read = CdbReader::read($file);
        unlink($file);

        ksort($raw, SORT_STRING);
        ksort($read, SORT_STRING);
        self::assertSame($raw, $read);
        self::assertArrayHasKey('/app/', $read, 'child lists are plain keys and come back too');
    }

    public function testReadOfAnEmptyModule(): void
    {
        $file = TempDir::file('.cdb');
        CdbWriter::write($file, []);

        self::assertSame([], CdbReader::read($file));
        unlink($file);
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(OpenException::class);
        $this->expectExceptionMessage('/nonexistent/dir/TREE.cdb: cannot open CDB');
        CdbReader::read('/nonexistent/dir/TREE.cdb');
    }

    public function testWithChildListsIsPublic(): void
    {
        self::assertSame(
            ['/a/b' => 's1', '/' => 'j["a"]', '/a/' => 'j["b"]'],
            ArraySource::withChildLists(['/a/b' => 's1']),
        );
    }
}
