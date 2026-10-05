<?php

declare(strict_types=1);

namespace Onlineconf;

use Onlineconf\Exception\InvalidDefaultException;
use Onlineconf\Exception\ParseException;

/**
 * Requested value type: the parser of an `s` value and of a getter's default, and the decoded value cache key.
 */
enum Type
{
    case String;
    case Int;
    case Float;
    case Bool;
    case Duration;
    case DurationMs;
    case Strings;
    case Array;
    case Mixed;

    /**
     * A getter's default, read with the rules of an `s` value of this type, so that an environment variable can
     * be passed as it is: `getInt('/port', env('PORT'))`.
     *
     * - `null`, and `''` (an empty variable is an unset one), give `null`; for String and Mixed the default is
     *   always taken as it is, `''` included;
     * - a value already of the type is taken as it is (an int for Float and Duration too); for Strings any array
     *   of strings, renumbered — what array_filter(explode(...)) leaves;
     * - a string is parsed as an `s` value: Int, Float, Duration, DurationMs as the node would be; Bool only from
     *   "0" or "1", stricter than a node; Strings as a comma-separated list, or as a JSON array of strings when it
     *   starts with "["; Array as JSON;
     * - anything else is an {@see InvalidDefaultException} naming the path and the type, with the value for the
     *   scalar types only — a list or a JSON document may hold a secret.
     *
     * @throws InvalidDefaultException
     */
    public function parseDefault(mixed $default, string $path): mixed
    {
        if ($default === null || $this === self::String || $this === self::Mixed) {
            return $default;
        }
        if ($default === '') {
            return null;
        }
        if (is_string($default)) {
            return $this->parseDefaultText($default, $path);
        }

        return match (true) {
            $this === self::Int, $this === self::DurationMs => is_int($default) ? $default : $this->wrongType($default, $path, 'int'),
            $this === self::Float, $this === self::Duration => is_int($default) || is_float($default) ? (float) $default : $this->wrongType($default, $path, 'float'),
            $this === self::Bool => is_bool($default) ? $default : $this->wrongType($default, $path, 'bool'),
            $this === self::Strings => is_array($default) && self::onlyStrings($default) ? array_values($default) : $this->invalid($path, 'expected an array of strings'),
            default => is_array($default) ? $default : $this->wrongType($default, $path, 'array'),
        };
    }

    /**
     * An `s` value of this type. Array and Mixed are not text types and give the data as it is.
     *
     * @throws ParseException with the bare reason, e.g. `"abc" is not an integer`
     */
    public function parseText(string $data): mixed
    {
        return match ($this) {
            self::Int => self::parseInt($data),
            self::Float => self::parseFloat($data),
            self::Bool => $data !== '' && $data !== '0',
            self::Duration => Duration::parse($data),
            self::DurationMs => (int) round(Duration::parse($data) * 1000),
            self::Strings => array_values(array_filter(array_map('trim', explode(',', $data)), static fn (string $s): bool => $s !== '')),
            default => $data,
        };
    }

    /**
     * The name used in messages.
     */
    public function label(): string
    {
        return match ($this) {
            self::String => 'string',
            self::Int => 'int',
            self::Float => 'float',
            self::Bool => 'bool',
            self::Duration => 'duration',
            self::DurationMs => 'duration_ms',
            self::Strings => 'strings',
            self::Array => 'array',
            self::Mixed => 'mixed',
        };
    }

    /**
     * @throws InvalidDefaultException
     */
    private function parseDefaultText(string $default, string $path): mixed
    {
        if ($this === self::Bool) {
            return match ($default) {
                '1' => true,
                '0' => false,
                default => $this->invalid($path, sprintf('"%s" is not "0" or "1"', $default)),
            };
        }
        if ($this === self::Array || ($this === self::Strings && str_starts_with(ltrim($default), '['))) {
            $value = json_decode($default, true);
            if ($this === self::Array) {
                return is_array($value) ? $value : $this->invalid($path, 'not a JSON array or object');
            }

            return is_array($value) && self::isStringList($value) ? $value : $this->invalid($path, 'not a JSON array of strings');
        }

        try {
            return $this->parseText($default);
        } catch (ParseException $e) {
            $this->invalid($path, $e->getMessage(), $e);
        }
    }

    /**
     * @throws InvalidDefaultException
     */
    private function wrongType(mixed $default, string $path, string $expected): never
    {
        $this->invalid($path, sprintf('expected %s or string, got %s', $expected, get_debug_type($default)));
    }

    /**
     * @throws InvalidDefaultException
     */
    private function invalid(string $path, string $reason, ?\Throwable $previous = null): never
    {
        throw new InvalidDefaultException(sprintf('%s: invalid default for %s: %s', $path, $this->label(), $reason), 0, $previous);
    }

    /**
     * @param array<mixed> $value
     *
     * @phpstan-assert-if-true array<string> $value
     */
    private static function onlyStrings(array $value): bool
    {
        return $value === array_filter($value, 'is_string');
    }

    /**
     * @param array<mixed> $value
     *
     * @phpstan-assert-if-true list<string> $value
     */
    private static function isStringList(array $value): bool
    {
        return array_is_list($value) && $value === array_filter($value, 'is_string');
    }

    /**
     * @throws ParseException
     */
    private static function parseInt(string $data): int
    {
        if (preg_match('/^[+-]?0*(\d+)$/D', $data, $match) !== 1) {
            throw new ParseException(sprintf('"%s" is not an integer', $data));
        }

        $value = (int) $data;
        if (ltrim((string) $value, '-') !== $match[1]) {
            throw new ParseException(sprintf('"%s" is out of integer range', $data));
        }

        return $value;
    }

    /**
     * @throws ParseException
     */
    private static function parseFloat(string $data): float
    {
        if (!is_numeric($data) || trim($data) !== $data) {
            throw new ParseException(sprintf('"%s" is not a number', $data));
        }

        return (float) $data;
    }
}
