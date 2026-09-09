<?php

declare(strict_types=1);

namespace Onlineconf\Tests\Support;

use Onlineconf\Source;
use Onlineconf\Source\ChangeTracking;

/**
 * Source over a raw array taken as is, child lists included; for testing how {@see \Onlineconf\Module}
 * handles data that {@see \Onlineconf\Source\ArraySource} would never produce (missing or malformed child lists).
 */
final class RawSource implements Source
{
    use ChangeTracking;

    /**
     * @param array<string, string> $raw path → raw value including the type byte
     */
    public function __construct(private array $raw, private readonly string $name = 'raw')
    {
    }

    /**
     * @param array<string, string> $raw
     */
    public function replace(array $raw): void
    {
        $this->raw = $raw;
        $this->markChanged();
    }

    public function getRaw(string $path): ?string
    {
        return $this->raw[$path] ?? null;
    }

    public function reloadIfChanged(): bool
    {
        return $this->consumeChanged();
    }

    public function version(): string
    {
        return (string) $this->generation;
    }

    public function name(): string
    {
        return $this->name;
    }
}
