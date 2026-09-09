<?php

declare(strict_types=1);

namespace Onlineconf\Tests\Support;

use Onlineconf\Source;

/**
 * Decorator that counts calls to the wrapped source.
 */
final class CountingSource implements Source
{
    public int $getRaw = 0;

    public int $reloadIfChanged = 0;

    public function __construct(private readonly Source $inner)
    {
    }

    public function getRaw(string $path): ?string
    {
        ++$this->getRaw;

        return $this->inner->getRaw($path);
    }

    public function reloadIfChanged(): bool
    {
        ++$this->reloadIfChanged;

        return $this->inner->reloadIfChanged();
    }

    public function version(): string
    {
        return $this->inner->version();
    }

    public function name(): string
    {
        return $this->inner->name();
    }
}
