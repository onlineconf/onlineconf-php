<?php

declare(strict_types=1);

namespace Onlineconf\Source;

use Onlineconf\Source;

/**
 * An optional module file that is not there (yet): no keys, version "missing". Every update check — every
 * check interval, as for any module — looks for the file again and, once it is there, serves it from then on,
 * so a process started before the updater delivered the module picks it up without a restart.
 *
 * Created by {@see \Onlineconf\Module::fromFile()} for an optional module.
 */
final class MissingFileSource implements Source
{
    private ?CdbSource $source = null;

    public function __construct(private readonly string $file)
    {
    }

    public function getRaw(string $path): ?string
    {
        return $this->source?->getRaw($path);
    }

    /**
     * {@inheritDoc}
     *
     * @throws \Onlineconf\Exception\OpenException when the file appeared but cannot be opened; it is tried again
     *                                             on the next check
     */
    public function reloadIfChanged(): bool
    {
        if ($this->source !== null) {
            return $this->source->reloadIfChanged();
        }
        if (!is_file($this->file)) {
            return false;
        }
        $this->source = new CdbSource($this->file);

        return true;
    }

    public function version(): string
    {
        return $this->source?->version() ?? 'missing';
    }

    public function name(): string
    {
        return basename($this->file, '.cdb');
    }

    /**
     * The file has not appeared so far.
     */
    public function isMissing(): bool
    {
        return $this->source === null;
    }

    public function file(): string
    {
        return $this->file;
    }
}
