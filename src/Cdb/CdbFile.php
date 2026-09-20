<?php

declare(strict_types=1);

namespace Onlineconf\Cdb;

use Onlineconf\Exception\OpenException;

/**
 * Validates that a file is a complete CDB file, shared by {@see \Onlineconf\Source\CdbSource} and
 * {@see CdbReader} so both reject a non-CDB or truncated file the same way instead of returning
 * silently wrong data.
 *
 * @internal
 */
final class CdbFile
{
    private const HEADER_SIZE = 2048;

    /**
     * Checks that the file is a complete CDB file and returns its stat().
     *
     * ext-dba opens any file as CDB without an error and answers "not found" for every key of a
     * broken one, so the check has to be done here. A CDB file is a 2 KB header (256 pairs of
     * "position, slots" of the hash tables), then the records, then the hash tables at the end of
     * the file. Every table must start after the header and end within the file; a truncated file
     * (an interrupted delivery) fails the second condition while its header still looks fine.
     * The header and the size are read from one descriptor, so they describe the same file even
     * if it is replaced meanwhile.
     *
     * @return array{ino: int, mtime: int, size: int}
     *
     * @throws OpenException when the file cannot be read or is not a complete CDB file
     */
    public static function validate(string $file): array
    {
        $stream = @fopen($file, 'rb');
        if ($stream === false) {
            throw new OpenException(sprintf('%s: %s: %s', $file, 'cannot open', error_get_last()['message'] ?? 'unknown error'));
        }
        $stat = fstat($stream);
        $header = @fread($stream, self::HEADER_SIZE);
        fclose($stream);

        if ($stat === false || $header === false || strlen($header) !== self::HEADER_SIZE) {
            throw new OpenException($file . ': not a valid CDB file');
        }

        /** @var array<int, int> $words 512 little-endian uint32, 1-based: position and slots of each hash table */
        $words = unpack('V' . self::HEADER_SIZE / 4, $header);
        for ($i = 1; $i <= self::HEADER_SIZE / 4; $i += 2) {
            if ($words[$i] < self::HEADER_SIZE || $words[$i] + $words[$i + 1] * 8 > $stat['size']) {
                throw new OpenException($file . ': not a valid CDB file');
            }
        }

        return $stat;
    }
}
