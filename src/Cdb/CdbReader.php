<?php

declare(strict_types=1);

namespace Onlineconf\Cdb;

use Onlineconf\Exception\OpenException;

/**
 * Reads every key/value pair of a CDB file, for tools that rebuild a module (CDB cannot be edited in place).
 *
 * Unlike {@see \Onlineconf\Source\CdbSource} this does no validation and no caching: it is a one-shot
 * dump of the file, child lists ("<path>/") included.
 */
final class CdbReader
{
    /**
     * @return array<string, string> path → raw value including the type byte
     *
     * @throws OpenException when the file cannot be opened as CDB
     */
    public static function read(string $file): array
    {
        $handle = @dba_open($file, 'r-', 'cdb');
        if ($handle === false) {
            throw new OpenException(sprintf('%s: cannot open CDB: %s', $file, error_get_last()['message'] ?? 'unknown error'));
        }

        $raw = [];
        for ($key = dba_firstkey($handle); $key !== false; $key = dba_nextkey($handle)) {
            $raw[$key] = (string) dba_fetch($key, $handle);
        }
        dba_close($handle);

        return $raw;
    }
}
