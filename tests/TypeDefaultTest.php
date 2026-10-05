<?php

declare(strict_types=1);

namespace Onlineconf\Tests;

use Onlineconf\Exception\InvalidDefaultException;
use Onlineconf\Type;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A getter's default, read with the rules of an `s` value: Type::parseDefault().
 */
final class TypeDefaultTest extends TestCase
{
    /**
     * @return iterable<string, array{Type, mixed, mixed}>
     */
    public static function valid(): iterable
    {
        foreach ([Type::Int, Type::Float, Type::Bool, Type::Duration, Type::DurationMs, Type::Strings, Type::Array] as $type) {
            yield $type->name . ': null' => [$type, null, null];
            yield $type->name . ': empty string is not set' => [$type, '', null];
        }
        yield 'Int: int' => [Type::Int, 5, 5];
        yield 'Int: string' => [Type::Int, '42', 42];
        yield 'Int: signed' => [Type::Int, '-7', -7];
        yield 'Int: plus' => [Type::Int, '+12', 12];
        yield 'Int: zeros' => [Type::Int, '007', 7];
        yield 'Float: float' => [Type::Float, 2.5, 2.5];
        yield 'Float: int' => [Type::Float, 3, 3.0];
        yield 'Float: string' => [Type::Float, '2.5', 2.5];
        yield 'Float: exponent' => [Type::Float, '1e3', 1000.0];
        yield 'Bool: true' => [Type::Bool, true, true];
        yield 'Bool: false' => [Type::Bool, false, false];
        yield 'Bool: "1"' => [Type::Bool, '1', true];
        yield 'Bool: "0"' => [Type::Bool, '0', false];
        yield 'Duration: float' => [Type::Duration, 1.5, 1.5];
        yield 'Duration: int' => [Type::Duration, 30, 30.0];
        yield 'Duration: seconds' => [Type::Duration, '30', 30.0];
        yield 'Duration: units' => [Type::Duration, '1m', 60.0];
        yield 'Duration: ms' => [Type::Duration, '300ms', 0.3];
        yield 'DurationMs: int' => [Type::DurationMs, 1500, 1500];
        yield 'DurationMs: units' => [Type::DurationMs, '1.5s', 1500];
        yield 'Strings: list' => [Type::Strings, ['a'], ['a']];
        yield 'Strings: empty list' => [Type::Strings, [], []];
        yield 'Strings: strings with keys, as array_filter(explode()) leaves them' => [Type::Strings, [0 => 'a', 2 => 'c'], ['a', 'c']];
        yield 'Strings: strings by name' => [Type::Strings, ['x' => 'a', 'y' => 'b'], ['a', 'b']];
        yield 'Strings: CSV' => [Type::Strings, 'a, b ,,c', ['a', 'b', 'c']];
        yield 'Strings: JSON' => [Type::Strings, '["a","b"]', ['a', 'b']];
        yield 'Strings: JSON after spaces' => [Type::Strings, ' ["x"]', ['x']];
        yield 'Array: array' => [Type::Array, ['k' => 1], ['k' => 1]];
        yield 'Array: JSON object' => [Type::Array, '{"pool":5}', ['pool' => 5]];
        yield 'Array: JSON list' => [Type::Array, '[1,2]', [1, 2]];
        yield 'String: as is' => [Type::String, 'x', 'x'];
        yield 'String: empty stays empty' => [Type::String, '', ''];
        yield 'String: null' => [Type::String, null, null];
        yield 'Mixed: as is' => [Type::Mixed, ['any'], ['any']];
    }

    #[DataProvider('valid')]
    public function testValid(Type $type, mixed $default, mixed $expected): void
    {
        self::assertSame($expected, $type->parseDefault($default, '/p'));
    }

    /**
     * @return iterable<string, array{Type, mixed, string}>
     */
    public static function invalid(): iterable
    {
        yield 'Int: text' => [Type::Int, 'abc', '/p: invalid default for int: "abc" is not an integer'];
        yield 'Int: fraction' => [Type::Int, '1.5', '/p: invalid default for int: "1.5" is not an integer'];
        yield 'Int: overflow' => [Type::Int, '99999999999999999999', 'is out of integer range'];
        yield 'Int: float' => [Type::Int, 1.5, '/p: invalid default for int: expected int or string, got float'];
        yield 'Float: text' => [Type::Float, 'x', '/p: invalid default for float: "x" is not a number'];
        yield 'Bool: true' => [Type::Bool, 'true', '/p: invalid default for bool: "true" is not "0" or "1"'];
        yield 'Bool: yes' => [Type::Bool, 'yes', '"yes" is not "0" or "1"'];
        yield 'Bool: int' => [Type::Bool, 1, '/p: invalid default for bool: expected bool or string, got int'];
        yield 'Duration: days' => [Type::Duration, '1d', '/p: invalid default for duration: invalid duration "1d"'];
        yield 'DurationMs: text' => [Type::DurationMs, 'abc', '/p: invalid default for duration_ms: invalid duration "abc"'];
        yield 'Strings: mixed JSON' => [Type::Strings, '["a",1]', '/p: invalid default for strings: not a JSON array of strings'];
        yield 'Strings: broken JSON' => [Type::Strings, '[bad', '/p: invalid default for strings: not a JSON array of strings'];
        yield 'Strings: list of ints' => [Type::Strings, [1, 2], '/p: invalid default for strings: expected an array of strings'];
        yield 'Strings: a string among others' => [Type::Strings, ['a', 1], '/p: invalid default for strings: expected an array of strings'];
        yield 'Array: text' => [Type::Array, 'text', '/p: invalid default for array: not a JSON array or object'];
        yield 'Array: broken JSON' => [Type::Array, '{bad', '/p: invalid default for array: not a JSON array or object'];
        yield 'Array: int' => [Type::Array, 5, '/p: invalid default for array: expected array or string, got int'];
    }

    #[DataProvider('invalid')]
    public function testInvalid(Type $type, mixed $default, string $message): void
    {
        $this->expectException(InvalidDefaultException::class);
        $this->expectExceptionMessage($message);

        $type->parseDefault($default, '/p');
    }

    public function testStringAndArrayDefaultsAreNotRepeatedInTheMessage(): void
    {
        foreach ([[Type::Strings, '["s3cret",1]'], [Type::Array, 's3cret']] as [$type, $default]) {
            try {
                $type->parseDefault($default, '/p');
                self::fail('the default does not parse');
            } catch (InvalidDefaultException $e) {
                self::assertStringNotContainsString('s3cret', $e->getMessage(), 'it may be a secret');
            }
        }
    }
}
