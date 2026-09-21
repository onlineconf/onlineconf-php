<?php

declare(strict_types=1);

namespace Onlineconf\Source;

use Onlineconf\Cdb\CdbFile;
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
     * @return array{ino: int, mtime: int, size: int}
     *
     * @throws OpenException when the file cannot be read or is not a complete CDB file
     *
     * @see CdbFile::validate() for the check itself, shared with {@see \Onlineconf\Cdb\CdbReader}
     */
    private function validate(): array
    {
        return CdbFile::validate($this->file);
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

    /**
     * @param array{ino: int, mtime: int, size: int} $stat
     */
    private static function formatVersion(array $stat): string
    {
        return $stat['ino'] . ':' . $stat['mtime'] . ':' . $stat['size'];
    }
}
