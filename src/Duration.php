<?php

declare(strict_types=1);

namespace Onlineconf;

use Onlineconf\Exception\ParseException;

/**
 * Parser of duration strings with units ("300ms", "2h45m", "1.5h", "-1m").
 *
 * A string without any of the letters "h", "m", "s" is treated as a number of seconds
 * ("30" → 30.0, "0.5" → 0.5), for compatibility with other OnlineConf clients.
 */
final class Duration
{
    private const UNITS = [
        'ns' => 1e-9,
        'us' => 1e-6,
        'µs' => 1e-6,
        'μs' => 1e-6,
        'ms' => 1e-3,
        's' => 1.0,
        'm' => 60.0,
        'h' => 3600.0,
    ];

    private const NUMBER = '(?:\d+(?:\.\d*)?|\.\d+)';
    private const UNIT = '(?:ns|us|µs|μs|ms|s|m|h)';

    /**
     * Parses a duration and returns the number of seconds.
     *
     * @throws ParseException when the string is not a valid duration
     */
    public static function parse(string $value): float
    {
        $normalized = strpbrk($value, 'hms') === false ? $value . 's' : $value;

        if (preg_match('/^([+-]?)(' . self::NUMBER . self::UNIT . ')+$/uD', $normalized, $match) !== 1) {
            throw new ParseException(sprintf('invalid duration "%s"', $value));
        }

        preg_match_all('/(' . self::NUMBER . ')(' . self::UNIT . ')/u', $normalized, $parts, PREG_SET_ORDER);

        $seconds = 0.0;
        foreach ($parts as $part) {
            $seconds += (float) $part[1] * self::UNITS[$part[2]];
        }

        return $match[1] === '-' ? -$seconds : $seconds;
    }
}
