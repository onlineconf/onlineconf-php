<?php

declare(strict_types=1);

namespace Onlineconf;

use Onlineconf\Exception\OpenException;

/**
 * Raw key/value storage a {@see Module} reads from.
 *
 * Implementations know nothing about value types, defaults or caching:
 * all of that lives in {@see Module}. The library ships {@see Source\CdbSource}
 * (production) and {@see Source\ArraySource} (tests); consumers may implement
 * their own, for example for local development without onlineconf-updater.
 */
interface Source
{
    /**
     * Returns the raw value including the leading type byte, or null when the key does not exist.
     */
    public function getRaw(string $path): ?string;

    /**
     * Checks whether the underlying data changed and reloads it if so.
     *
     * Returns true when the data was reloaded (and {@see version()} changed).
     *
     * @throws OpenException when the data changed but cannot be reloaded; the old data must stay available
     */
    public function reloadIfChanged(): bool;

    /**
     * Identifier of the currently loaded data; changes on every reload.
     */
    public function version(): string;

    /**
     * Human-readable name used in error messages and logs.
     */
    public function name(): string;
}
