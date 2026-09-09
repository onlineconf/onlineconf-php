<?php

declare(strict_types=1);

namespace Onlineconf\Tests;

use Onlineconf\Duration;
use Onlineconf\Exception\ParseException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DurationTest extends TestCase
{
    /**
     * @return iterable<string, array{string, float}>
     */
    public static function valid(): iterable
    {
        yield 'bare integer = seconds' => ['30', 30.0];
        yield 'bare fraction = seconds' => ['0.5', 0.5];
        yield 'zero' => ['0', 0.0];
        yield 'milliseconds' => ['300ms', 0.3];
        yield 'seconds' => ['30s', 30.0];
        yield 'fraction of second' => ['1.5s', 1.5];
        yield 'minutes' => ['5m', 300.0];
        yield 'hours and minutes' => ['2h45m', 9900.0];
        yield 'h m s' => ['1h30m10s', 5410.0];
        yield 'fractional hours' => ['1.5h', 5400.0];
        yield 'negative' => ['-1m', -60.0];
        yield 'explicit plus' => ['+2s', 2.0];
        yield 'microseconds ascii' => ['1500us', 0.0015];
        yield 'microseconds micro sign' => ['1500µs', 0.0015];
        yield 'microseconds greek mu' => ['1500μs', 0.0015];
        yield 'nanoseconds' => ['1000000ns', 0.001];
        yield 'leading dot' => ['.5s', 0.5];
        yield 'trailing dot' => ['1.s', 1.0];
        yield 'zero with unit' => ['0h', 0.0];
    }

    #[DataProvider('valid')]
    public function testParse(string $input, float $expected): void
    {
        self::assertEqualsWithDelta($expected, Duration::parse($input), 1e-12);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield 'days' => ['1d'];
        yield 'space before unit' => ['1 h'];
        yield 'space inside' => ['1.5 h'];
        yield 'text' => ['abc'];
        yield 'unit only' => ['s'];
        yield 'sign only' => ['-'];
        yield 'double sign' => ['--1s'];
        yield 'unit before number' => ['s1'];
        yield 'trailing garbage' => ['1s!'];
        yield 'comma' => ['1,5s'];
    }

    #[DataProvider('invalid')]
    public function testParseError(string $input): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage(sprintf('invalid duration "%s"', $input));
        Duration::parse($input);
    }
}
