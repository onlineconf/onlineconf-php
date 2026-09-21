<?php

declare(strict_types=1);

namespace Onlineconf\Cdb;

use Onlineconf\Exception\OpenException;

/**
 * Reads every key/value pair of a CDB file, for tools that rebuild a module (CDB cannot be edited in place).
 *
 * Unlike {@see \Onlineconf\Source\CdbSource} this does no caching: it is a one-shot dump of the file,
 * child lists ("<path>/") included. The file is validated the same way {@see \Onlineconf\Source\CdbSource}
 * does, so a non-CDB or truncated file throws instead of silently returning wrong or partial data.
 */
final class CdbReader
{
    /**
     * @return array<string, string> path → raw value including the type byte
     *
     * @throws OpenException when the file cannot be opened or is not a complete CDB file
     */
    public static function read(string $file): array
    {
        CdbFile::validate($file);

        $handle = @dba_open($file, 'r-', 'cdb');
        if ($handle === false) {
            // the file has just passed validation; only a concurrent replacement gets here
            // @codeCoverageIgnoreStart
            throw new OpenException(sprintf('%s: %s: %s', $file, 'cannot open CDB', error_get_last()['message'] ?? 'unknown error'));
            // @codeCoverageIgnoreEnd
        }

        $raw = [];
        for ($key = dba_firstkey($handle); $key !== false; $key = dba_nextkey($handle)) {
            $raw[$key] = (string) dba_fetch($key, $handle);
        }
        dba_close($handle);

        return $raw;
    }
}
