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
        $this->expectExceptionMessage('/nonexistent/dir/TREE.cdb: cannot open');
        CdbReader::read('/nonexistent/dir/TREE.cdb');
    }

    public function testReadOfATextFileThrows(): void
    {
        $file = TempDir::file('.cdb');
        file_put_contents($file, "not a cdb file\n");

        try {
            $this->expectException(OpenException::class);
            $this->expectExceptionMessage($file . ': not a valid CDB file');
            CdbReader::read($file);
        } finally {
            unlink($file);
        }
    }

    public function testReadOfATruncatedFileThrows(): void
    {
        $file = TempDir::file('.cdb');
        CdbWriter::write($file, ArraySource::rawFromValues(['/app/name' => 'demo', '/app/opts' => ['pool' => 5]]));
        file_put_contents($file, substr((string) file_get_contents($file), 0, -20));

        try {
            $this->expectException(OpenException::class);
            $this->expectExceptionMessage($file . ': not a valid CDB file');
            CdbReader::read($file);
        } finally {
            unlink($file);
        }
    }

    public function testNumericStringKeyRoundTripsThroughReadAndWithChildLists(): void
    {
        // PHP turns a numeric-string key into an int key on every assignment below; CdbWriter
        // and withChildLists() must accept that instead of raising a TypeError under
        // strict_types. $key is read through a string-typed boundary so phpstan keeps treating
        // it as an ordinary string, exactly like the "string path" callers of this library
        // believe they are always dealing with — the mismatch this test exercises.
        $key = self::numericPathKey();

        $file = TempDir::file('.cdb');
        CdbWriter::write($file, [$key => 's1']);

        $read = CdbReader::read($file);
        CdbWriter::write($file, ArraySource::withChildLists($read));

        $roundTripped = CdbReader::read($file);
        unlink($file);

        self::assertSame('s1', $roundTripped[$key]);
    }

    private static function numericPathKey(): string
    {
        return '123';
    }

    public function testWithChildListsIsPublic(): void
    {
        self::assertSame(
            ['/a/b' => 's1', '/' => 'j["a"]', '/a/' => 'j["b"]'],
            ArraySource::withChildLists(['/a/b' => 's1']),
        );
    }
}
