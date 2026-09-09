<?php

declare(strict_types=1);

namespace Onlineconf\Source;

use Onlineconf\Exception\OpenException;
use Onlineconf\Source;

/**
 * Reads a CDB file through ext-dba (handler "cdb").
 *
 * The file is expected to be replaced atomically (write to a temporary file, then rename),
 * as onlineconf-updater and onlineconf-csi-driver do. An update is detected by a change of
 * inode, mtime or size reported by stat(); the open handle keeps reading the old inode until
 * the new file is opened successfully.
 */
final class CdbSource implements Source
{
    private const HEADER_SIZE = 2048;

    /** @var resource|\Dba\Connection resource up to PHP 8.3, Dba\Connection object since PHP 8.4 */
    private $handle; // @phpstan-ignore class.notFound (Dba\Connection exists since PHP 8.4, analysis runs as PHP 8.1), property.unusedType (assigned by dba_open() on PHP 8.4)

    private string $version;

    /**
     * @throws OpenException when the file cannot be stat-ed or opened as CDB
     */
    public function __construct(private readonly string $file)
    {
        [$this->handle, $this->version] = $this->load();
    }

    public function getRaw(string $path): ?string
    {
        $value = dba_fetch($path, $this->handle); // @phpstan-ignore argument.type (handle is a resource or a Dba\Connection depending on the PHP version)

        return $value === false ? null : $value;
    }

    public function reloadIfChanged(): bool
    {
        if ($this->statVersion() === $this->version) {
            return false;
        }

        [$handle, $version] = $this->load();
        dba_close($this->handle); // @phpstan-ignore argument.type (handle is a resource or a Dba\Connection depending on the PHP version)
        $this->handle = $handle;
        $this->version = $version;

        return true;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function name(): string
    {
        return basename($this->file, '.cdb');
    }

    /**
     * Validates and opens the file, making sure the handle and the version describe the same file.
     *
     * onlineconf-updater may rename a new file into place between validate() and open(); the handle
     * would then read the new file while the version still describes the old one, and version()
     * (which workers compare to react to changes) would lie until the next check reopened the file.
     * A stat() after the open detects that and repeats the sequence.
     *
     * @return array{resource, string} handle (a Dba\Connection object since PHP 8.4) and version
     *
     * @throws OpenException
     */
    private function load(): array
    {
        do {
            $stat = $this->validate();
            $handle = $this->open();
            $version = self::formatVersion($stat);
            $replaced = $this->statVersion() !== $version;
            if ($replaced) {
                // @codeCoverageIgnoreStart
                dba_close($handle);
                // @codeCoverageIgnoreEnd
            }
        } while ($replaced);

        return [$handle, $version];
    }

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
    private function validate(): array
    {
        $stream = @fopen($this->file, 'rb');
        if ($stream === false) {
            throw $this->error('cannot open');
        }
        $stat = fstat($stream);
        $header = @fread($stream, self::HEADER_SIZE);
        fclose($stream);

        if ($stat === false || $header === false || strlen($header) !== self::HEADER_SIZE) {
            throw $this->invalid();
        }

        /** @var array<int, int> $words 512 little-endian uint32, 1-based: position and slots of each hash table */
        $words = unpack('V' . self::HEADER_SIZE / 4, $header);
        for ($i = 1; $i <= self::HEADER_SIZE / 4; $i += 2) {
            if ($words[$i] < self::HEADER_SIZE || $words[$i] + $words[$i + 1] * 8 > $stat['size']) {
                throw $this->invalid();
            }
        }

        return $stat;
    }

    /**
     * @return resource a Dba\Connection object since PHP 8.4
     *
     * @throws OpenException
     */
    private function open()
    {
        // "r-": read-only, no locking. The file is never modified in place and the directory may be read-only.
        $handle = @dba_open($this->file, 'r-', 'cdb');
        if ($handle === false) {
            // the file has just passed validate(); only a concurrent replacement gets here
            // @codeCoverageIgnoreStart
            throw $this->error('cannot open CDB');
            // @codeCoverageIgnoreEnd
        }

        return $handle;
    }

    private function statVersion(): string
    {
        clearstatcache(true, $this->file);
        $stat = @stat($this->file);
        if ($stat === false) {
            throw $this->error('cannot stat');
        }

        return self::formatVersion($stat);
    }

    /**
     * Exception for a failed PHP call, with the message of the last PHP error.
     */
    private function error(string $reason): OpenException
    {
        return new OpenException(sprintf('%s: %s: %s', $this->file, $reason, error_get_last()['message'] ?? 'unknown error'));
    }

    private function invalid(): OpenException
    {
        return new OpenException($this->file . ': not a valid CDB file');
    }

    /**
     * @param array{ino: int, mtime: int, size: int} $stat
     */
    private static function formatVersion(array $stat): string
    {
        return $stat['ino'] . ':' . $stat['mtime'] . ':' . $stat['size'];
    }
}
