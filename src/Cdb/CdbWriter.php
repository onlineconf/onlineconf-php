<?php

declare(strict_types=1);

namespace Onlineconf\Cdb;

use Onlineconf\Exception\WriteException;
use Onlineconf\Source\ArraySource;

/**
 * Writes CDB files (pure PHP implementation of D. J. Bernstein's format), byte-identical to ext-dba's cdb_make.
 *
 * The file is written to a temporary name and renamed into place, exactly like onlineconf-updater does,
 * so a reader never sees a half-written module. Meant for tools that build or edit local modules
 * (tests, development without onlineconf-updater); production modules come from the updater.
 */
final class CdbWriter
{
    /**
     * @param array<string, string> $raw path → raw value including the type byte
     *
     * @throws WriteException when the file cannot be written
     */
    public static function write(string $file, array $raw): void
    {
        $records = '';
        /** @var array<int, list<array{int, int}>> $tables */
        $tables = array_fill(0, 256, []);
        $position = 2048;

        foreach ($raw as $key => $value) {
            // PHP turns numeric array keys into integers; keys are strings
            $key = (string) $key;
            $hash = self::hash($key);
            $tables[$hash & 255][] = [$hash, $position];
            $records .= pack('VV', strlen($key), strlen($value)) . $key . $value;
            $position += 8 + strlen($key) + strlen($value);
        }

        $header = '';
        $body = '';
        foreach ($tables as $entries) {
            $length = count($entries) * 2;
            $header .= pack('VV', $position, $length);
            $slots = array_fill(0, $length, [0, 0]);
            foreach ($entries as [$hash, $recordPosition]) {
                $slot = ($hash >> 8) % max($length, 1);
                while ($slots[$slot][1] !== 0) {
                    $slot = ($slot + 1) % $length;
                }
                $slots[$slot] = [$hash, $recordPosition];
            }
            foreach ($slots as [$hash, $recordPosition]) {
                $body .= pack('VV', $hash, $recordPosition);
            }
            $position += $length * 8;
        }

        $tmp = $file . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $header . $records . $body) === false) {
            throw new WriteException(sprintf('%s: cannot write: %s', $file, error_get_last()['message'] ?? 'unknown error'));
        }
        if (@rename($tmp, $file) === false) {
            $message = error_get_last()['message'] ?? 'unknown error';
            @unlink($tmp);
            throw new WriteException(sprintf('%s: cannot write: %s', $file, $message));
        }
    }

    /**
     * @param array<string, string|int|float|bool|array<mixed>|null> $values path → PHP value (see ArraySource::fromValues())
     *
     * @throws WriteException when the file cannot be written
     */
    public static function writeValues(string $file, array $values): void
    {
        self::write($file, ArraySource::rawFromValues($values));
    }

    private static function hash(string $key): int
    {
        $hash = 5381;
        for ($i = 0, $length = strlen($key); $i < $length; ++$i) {
            $hash = ((($hash << 5) + $hash) ^ ord($key[$i])) & 0xFFFFFFFF;
        }

        return $hash;
    }
}
