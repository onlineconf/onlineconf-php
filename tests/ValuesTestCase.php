<?php

declare(strict_types=1);

namespace Onlineconf\Tests;

use Onlineconf\Exception\FormatException;
use Onlineconf\Exception\InvalidJsonException;
use Onlineconf\Exception\NotFoundException;
use Onlineconf\Exception\ParseException;
use Onlineconf\Module;
use Onlineconf\Source;
use Onlineconf\Tests\Support\CountingSource;
use Onlineconf\Tests\Support\TestLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Value semantics shared by all sources; subclasses provide the source.
 */
abstract class ValuesTestCase extends TestCase
{
    protected TestLogger $logger;

    protected CountingSource $source;

    protected Module $module;

    /**
     * @param array<string, string> $raw
     */
    abstract protected function createSource(array $raw): Source;

    protected function setUp(): void
    {
        $this->logger = new TestLogger();
        $this->source = new CountingSource($this->createSource(self::raw()));
        $this->module = new Module($this->source, $this->logger, 0);
    }

    /**
     * @return array<string, string>
     */
    protected static function raw(): array
    {
        return [
            '/str' => 'shello',
            '/empty' => 's',
            '/utf8' => 's Привет, мир! ',
            '/int' => 's42',
            '/int/neg' => 's-7',
            '/int/plus' => 's+12',
            '/int/zeros' => 's007',
            '/int/max' => 's' . PHP_INT_MAX,
            '/int/overflow' => 's99999999999999999999',
            '/int/float' => 's12.5',
            '/int/space' => 's 12',
            '/int/hex' => 's0x1A',
            '/int/exp' => 's1e3',
            '/float' => 's2.5',
            '/float/exp' => 's1e3',
            '/float/int' => 's3',
            '/float/space' => 's 1.5',
            '/float/bad' => 's1,5',
            '/bool/empty' => 's',
            '/bool/zero' => 's0',
            '/bool/one' => 's1',
            '/bool/false' => 'sfalse',
            '/bool/abc' => 'sabc',
            '/duration/seconds' => 's30',
            '/duration/fraction' => 's0.5',
            '/duration/ms' => 's300ms',
            '/duration/hm' => 's2h45m',
            '/duration/neg' => 's-1m',
            '/duration/bad' => 's1d',
            '/duration/empty' => 's',
            '/strings/csv' => 'sa, b ,,c',
            '/strings/json' => 'j["a","b"]',
            '/strings/object' => 'j{"a":1}',
            '/strings/mixed' => 'j["a",1]',
            '/array/object' => 'j{"pool":5,"hosts":["a","b"]}',
            '/array/list' => 'j[1,2,3]',
            '/array/scalar' => 'j"text"',
            '/json/broken' => 'j{"a":',
            '/json/null' => 'jnull',
            '/cbor' => "c\xa1\x61a\x01",
        ];
    }

    public function testStringValues(): void
    {
        self::assertSame('hello', $this->module->getString('/str', 'dfl'));
        self::assertSame('', $this->module->getString('/empty', 'dfl'));
        self::assertSame(' Привет, мир! ', $this->module->getString('/utf8', 'dfl'));
        self::assertSame('hello', $this->module->requireString('/str'));
        self::assertSame('dfl', $this->module->getString('/missing', 'dfl'));
        self::assertSame([], $this->logger->records);
    }

    public function testHas(): void
    {
        self::assertTrue($this->module->has('/str'));
        self::assertTrue($this->module->has('/empty'));
        self::assertTrue($this->module->has('/json/broken'));
        self::assertTrue($this->module->has('/cbor'));
        self::assertFalse($this->module->has('/missing'));
    }

    public function testIntValues(): void
    {
        self::assertSame(42, $this->module->getInt('/int', 0));
        self::assertSame(-7, $this->module->getInt('/int/neg', 0));
        self::assertSame(12, $this->module->getInt('/int/plus', 0));
        self::assertSame(7, $this->module->getInt('/int/zeros', 0));
        self::assertSame(PHP_INT_MAX, $this->module->getInt('/int/max', 0));
        self::assertSame(42, $this->module->requireInt('/int'));
        self::assertSame(-1, $this->module->getInt('/missing', -1));
        self::assertSame([], $this->logger->records);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badInts(): iterable
    {
        yield 'float' => ['/int/float', 'is not an integer'];
        yield 'leading space' => ['/int/space', 'is not an integer'];
        yield 'hex' => ['/int/hex', 'is not an integer'];
        yield 'exponent' => ['/int/exp', 'is not an integer'];
        yield 'text' => ['/str', 'is not an integer'];
        yield 'empty' => ['/empty', 'is not an integer'];
        yield 'overflow' => ['/int/overflow', 'out of integer range'];
    }

    #[DataProvider('badInts')]
    public function testBadInt(string $path, string $error): void
    {
        self::assertSame(99, $this->module->getInt($path, 99));
        self::assertCount(1, $this->logger->records);
        self::assertStringContainsString($error, $this->logger->records[0][1]);
        self::assertSame('warning', $this->logger->records[0][0]);

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage($error);
        $this->module->requireInt($path);
    }

    public function testFloatValues(): void
    {
        self::assertSame(2.5, $this->module->getFloat('/float', 0.0));
        self::assertSame(1000.0, $this->module->getFloat('/float/exp', 0.0));
        self::assertSame(3.0, $this->module->getFloat('/float/int', 0.0));
        self::assertSame(3.0, $this->module->requireFloat('/float/int'));
        self::assertSame(1.5, $this->module->getFloat('/missing', 1.5));
        self::assertSame([], $this->logger->records);

        self::assertSame(2.7, $this->module->getFloat('/float/space', 2.7));
        self::assertSame(2.7, $this->module->getFloat('/float/bad', 2.7));
        self::assertSame(2.7, $this->module->getFloat('/str', 2.7));
        self::assertCount(3, $this->logger->records);
        self::assertStringContainsString('is not a number', $this->logger->records[0][1]);

        $this->expectException(ParseException::class);
        $this->module->requireFloat('/float/bad');
    }

    public function testBoolValues(): void
    {
        self::assertFalse($this->module->getBool('/bool/empty', true));
        self::assertFalse($this->module->getBool('/bool/zero', true));
        self::assertTrue($this->module->getBool('/bool/one', false));
        self::assertTrue($this->module->getBool('/bool/false', false));
        self::assertTrue($this->module->getBool('/bool/abc', false));
        self::assertTrue($this->module->requireBool('/bool/one'));
        self::assertTrue($this->module->getBool('/missing', true));
        self::assertFalse($this->module->getBool('/missing', false));
        self::assertSame([], $this->logger->records);
    }

    public function testDurationValues(): void
    {
        self::assertSame(30.0, $this->module->getDuration('/duration/seconds', 0.0));
        self::assertSame(0.5, $this->module->getDuration('/duration/fraction', 0.0));
        self::assertSame(0.3, $this->module->getDuration('/duration/ms', 0.0));
        self::assertSame(9900.0, $this->module->getDuration('/duration/hm', 0.0));
        self::assertSame(-60.0, $this->module->getDuration('/duration/neg', 0.0));
        self::assertSame(9900.0, $this->module->requireDuration('/duration/hm'));
        self::assertSame(1.5, $this->module->getDuration('/missing', 1.5));

        self::assertSame(30000, $this->module->getDurationMs('/duration/seconds', 0));
        self::assertSame(500, $this->module->getDurationMs('/duration/fraction', 0));
        self::assertSame(300, $this->module->getDurationMs('/duration/ms', 0));
        self::assertSame(-60000, $this->module->getDurationMs('/duration/neg', 0));
        self::assertSame(300, $this->module->requireDurationMs('/duration/ms'));
        self::assertSame(7, $this->module->getDurationMs('/missing', 7));
        self::assertSame([], $this->logger->records);

        self::assertSame(1.0, $this->module->getDuration('/duration/bad', 1.0));
        self::assertSame(1.0, $this->module->getDuration('/duration/empty', 1.0));
        self::assertSame(1, $this->module->getDurationMs('/str', 1));
        self::assertCount(3, $this->logger->records);
        self::assertStringContainsString('invalid duration "1d"', $this->logger->records[0][1]);

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('invalid duration "1d"');
        $this->module->requireDuration('/duration/bad');
    }

    public function testDurationMsParseErrorIsStrict(): void
    {
        $this->expectException(ParseException::class);
        $this->module->requireDurationMs('/duration/empty');
    }

    public function testStringsValues(): void
    {
        self::assertSame(['a', 'b', 'c'], $this->module->getStrings('/strings/csv', []));
        self::assertSame(['a', 'b'], $this->module->getStrings('/strings/json', []));
        self::assertSame(['hello'], $this->module->getStrings('/str', []));
        self::assertSame([], $this->module->getStrings('/empty', ['x']));
        self::assertSame(['a', 'b'], $this->module->requireStrings('/strings/json'));
        self::assertSame(['d'], $this->module->getStrings('/missing', ['d']));
        self::assertSame([], $this->logger->records);

        self::assertSame(['d'], $this->module->getStrings('/strings/object', ['d']));
        self::assertSame(['d'], $this->module->getStrings('/strings/mixed', ['d']));
        self::assertSame(['d'], $this->module->getStrings('/cbor', ['d']));
        self::assertCount(3, $this->logger->records);
        self::assertStringContainsString('is not an array of strings', $this->logger->records[0][1]);
        self::assertStringContainsString('format is not a string', $this->logger->records[2][1]);

        $this->expectException(FormatException::class);
        $this->module->requireStrings('/strings/object');
    }

    public function testArrayValues(): void
    {
        self::assertSame(['pool' => 5, 'hosts' => ['a', 'b']], $this->module->getArray('/array/object', []));
        self::assertSame([1, 2, 3], $this->module->getArray('/array/list', []));
        self::assertSame([1, 2, 3], $this->module->requireArray('/array/list'));
        self::assertSame(['d'], $this->module->getArray('/missing', ['d']));
        self::assertSame([], $this->logger->records);

        self::assertSame(['d'], $this->module->getArray('/str', ['d']));
        self::assertSame(['d'], $this->module->getArray('/array/scalar', ['d']));
        self::assertSame(['d'], $this->module->getArray('/json/null', ['d']));
        self::assertSame(['d'], $this->module->getArray('/cbor', ['d']));
        self::assertSame(
            ['format is not JSON', 'JSON value is not an array or object', 'JSON value is not an array or object', "unexpected format 'c'"],
            $this->logger->reasons('warning'),
        );

        $this->expectException(FormatException::class);
        $this->expectExceptionMessage('format is not JSON');
        $this->module->requireArray('/str');
    }

    public function testMixedValues(): void
    {
        self::assertSame('hello', $this->module->get('/str', null));
        self::assertSame('', $this->module->get('/empty', 'dfl'));
        self::assertSame(['pool' => 5, 'hosts' => ['a', 'b']], $this->module->get('/array/object', null));
        self::assertSame('text', $this->module->get('/array/scalar', null));
        self::assertNull($this->module->get('/json/null', 'dfl'));
        self::assertSame('dfl', $this->module->get('/missing', 'dfl'));
        self::assertSame([], $this->logger->records);

        self::assertSame('dfl', $this->module->get('/cbor', 'dfl'));
        self::assertSame(["unexpected format 'c'"], $this->logger->reasons('warning'));
    }

    public function testRequireMixed(): void
    {
        self::assertSame('hello', $this->module->require('/str'));
        self::assertSame(['pool' => 5, 'hosts' => ['a', 'b']], $this->module->require('/array/object'));
        self::assertNull($this->module->require('/json/null'));

        try {
            $this->module->require('/missing');
            self::fail('NotFoundException expected');
        } catch (NotFoundException) {
        }

        $this->expectException(FormatException::class);
        $this->expectExceptionMessage("unexpected format 'c'");
        $this->module->require('/cbor');
    }

    public function testFormatMismatchIsLoggedAndDefaultReturned(): void
    {
        self::assertSame('dfl', $this->module->getString('/array/object', 'dfl'));
        self::assertSame('dfl', $this->module->getString('/cbor', 'dfl'));
        self::assertSame(1, $this->module->getInt('/array/list', 1));
        self::assertTrue($this->module->getBool('/array/list', true));
        self::assertSame(1.0, $this->module->getFloat('/array/list', 1.0));
        self::assertSame(1.0, $this->module->getDuration('/array/list', 1.0));
        self::assertSame(6, $this->logger->count('warning'));
        foreach ($this->logger->messages() as $message) {
            self::assertStringContainsString('format is not a string', $message);
            self::assertStringStartsWith('onlineconf: ' . $this->module->name() . ':/', $message);
        }

        $this->expectException(FormatException::class);
        $this->expectExceptionMessage($this->module->name() . ':/array/object: format is not a string');
        $this->module->requireString('/array/object');
    }

    public function testRequireMissingKey(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage(':/missing: key not found');
        $this->module->requireInt('/missing');
    }

    /**
     * @return iterable<string, array{callable(Module): mixed}>
     */
    public static function jsonDecoders(): iterable
    {
        yield 'get' => [static fn (Module $m): mixed => $m->get('/json/broken', 'dfl')];
        yield 'require' => [static fn (Module $m): mixed => $m->require('/json/broken')];
        yield 'getArray' => [static fn (Module $m): mixed => $m->getArray('/json/broken', [])];
        yield 'getStrings' => [static fn (Module $m): mixed => $m->getStrings('/json/broken', [])];
        yield 'requireArray' => [static fn (Module $m): mixed => $m->requireArray('/json/broken')];
        yield 'requireStrings' => [static fn (Module $m): mixed => $m->requireStrings('/json/broken')];
    }

    /**
     * @param callable(Module): mixed $call
     */
    #[DataProvider('jsonDecoders')]
    public function testInvalidJsonIsCritical(callable $call): void
    {
        try {
            $call($this->module);
            self::fail('InvalidJsonException expected');
        } catch (InvalidJsonException $e) {
            self::assertSame($this->module->name() . ':/json/broken: invalid JSON: Syntax error', $e->getMessage());
        }
        self::assertSame([], $this->logger->records, 'invalid JSON is not logged, the exception is the signal');

        // nothing was cached: the same call fails again and hits the source only for the raw bytes once
        $reads = $this->source->getRaw;
        try {
            $call($this->module);
            self::fail('InvalidJsonException expected again');
        } catch (InvalidJsonException) {
        }
        self::assertSame($reads, $this->source->getRaw);
    }

    public function testInvalidJsonDoesNotAffectNonDecodingGetters(): void
    {
        self::assertTrue($this->module->has('/json/broken'));
        self::assertSame('dfl', $this->module->getString('/json/broken', 'dfl'));
        self::assertSame(1, $this->module->getInt('/json/broken', 1));
        self::assertSame(2, $this->logger->count('warning'));

        $this->expectException(FormatException::class);
        $this->module->requireString('/json/broken');
    }

    public function testValuesAreCachedUntilReload(): void
    {
        for ($i = 0; $i < 1000; ++$i) {
            self::assertSame('hello', $this->module->getString('/str', 'dfl'));
            self::assertSame(['pool' => 5, 'hosts' => ['a', 'b']], $this->module->getArray('/array/object', []));
            self::assertSame('dfl', $this->module->getString('/missing', 'dfl'));
        }
        self::assertSame(3, $this->source->getRaw, 'one raw read per unique key');

        self::assertSame(0.3, $this->module->getDuration('/duration/ms', 0.0));
        self::assertSame(300, $this->module->getDurationMs('/duration/ms', 0));
        self::assertSame('300ms', $this->module->getString('/duration/ms', ''));
        self::assertSame(4, $this->source->getRaw, 'raw bytes are shared between requested types');
    }

    public function testFailedDecodeIsNotCached(): void
    {
        self::assertSame(1, $this->module->getInt('/str', 1));
        self::assertSame(1, $this->module->getInt('/str', 1));
        self::assertSame(2, $this->logger->count('warning'), 'each failed access is logged');
        self::assertSame(1, $this->source->getRaw);
    }
}
