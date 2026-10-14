<?php

declare(strict_types=1);

namespace Onlineconf\Source;

use Onlineconf\Source;

/**
 * The module of a disabled client (ONLINECONF_ENABLED=false): no keys, version "disabled". Unlike
 * {@see MissingFileSource}, no file is ever opened or checked for, so a reload never happens.
 *
 * @internal created by {@see \Onlineconf\Module::fromSettings()} and the {@see \Onlineconf\Onlineconf} registry
 */
final class DisabledSource implements Source
{
    public function __construct(private readonly string $name)
    {
    }

    public function getRaw(string $path): ?string
    {
        return null;
    }

    public function reloadIfChanged(): bool
    {
        return false;
    }

    public function version(): string
    {
        return 'disabled';
    }

    public function name(): string
    {
        return $this->name;
    }
}
