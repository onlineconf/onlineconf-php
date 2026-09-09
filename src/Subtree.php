<?php

declare(strict_types=1);

namespace Onlineconf;

use Onlineconf\Exception\FormatException;
use Onlineconf\Exception\InvalidJsonException;
use Onlineconf\Exception\NotFoundException;
use Onlineconf\Exception\ParseException;

/**
 * A view of a {@see Module} restricted to a path prefix.
 *
 * Paths passed to the getters must start with "/" and are simply concatenated with the prefix.
 * Path separators other than "/" are not supported.
 */
final class Subtree
{
    /**
     * @internal use {@see Module::subtree()}
     */
    public function __construct(private readonly Module $module, private readonly string $prefix)
    {
    }

    /**
     * Full path in the module for a path relative to the prefix.
     */
    public function path(string $path): string
    {
        return $this->prefix . $path;
    }

    public function getRaw(string $path): ?string
    {
        return $this->module->getRaw($this->path($path));
    }

    public function has(string $path): bool
    {
        return $this->module->has($this->path($path));
    }

    public function getString(string $path, string $default): string
    {
        return $this->module->getString($this->path($path), $default);
    }

    public function getInt(string $path, int $default): int
    {
        return $this->module->getInt($this->path($path), $default);
    }

    public function getFloat(string $path, float $default): float
    {
        return $this->module->getFloat($this->path($path), $default);
    }

    public function getBool(string $path, bool $default): bool
    {
        return $this->module->getBool($this->path($path), $default);
    }

    public function getDuration(string $path, float $default): float
    {
        return $this->module->getDuration($this->path($path), $default);
    }

    public function getDurationMs(string $path, int $default): int
    {
        return $this->module->getDurationMs($this->path($path), $default);
    }

    /**
     * @param list<string> $default
     *
     * @return list<string>
     */
    public function getStrings(string $path, array $default): array
    {
        return $this->module->getStrings($this->path($path), $default);
    }

    /**
     * @param array<mixed> $default
     *
     * @return array<mixed>
     *
     * @throws InvalidJsonException
     */
    public function getArray(string $path, array $default): array
    {
        return $this->module->getArray($this->path($path), $default);
    }

    /**
     * @throws InvalidJsonException
     */
    public function get(string $path, mixed $default): mixed
    {
        return $this->module->get($this->path($path), $default);
    }

    /**
     * @throws NotFoundException|FormatException|InvalidJsonException
     */
    public function require(string $path): mixed
    {
        return $this->module->require($this->path($path));
    }

    /**
     * @throws NotFoundException|FormatException
     */
    public function requireString(string $path): string
    {
        return $this->module->requireString($this->path($path));
    }

    /**
     * @throws NotFoundException|FormatException|ParseException
     */
    public function requireInt(string $path): int
    {
        return $this->module->requireInt($this->path($path));
    }

    /**
     * @throws NotFoundException|FormatException|ParseException
     */
    public function requireFloat(string $path): float
    {
        return $this->module->requireFloat($this->path($path));
    }

    /**
     * @throws NotFoundException|FormatException
     */
    public function requireBool(string $path): bool
    {
        return $this->module->requireBool($this->path($path));
    }

    /**
     * @throws NotFoundException|FormatException|ParseException
     */
    public function requireDuration(string $path): float
    {
        return $this->module->requireDuration($this->path($path));
    }

    /**
     * @throws NotFoundException|FormatException|ParseException
     */
    public function requireDurationMs(string $path): int
    {
        return $this->module->requireDurationMs($this->path($path));
    }

    /**
     * @return list<string>
     *
     * @throws NotFoundException|FormatException|InvalidJsonException
     */
    public function requireStrings(string $path): array
    {
        return $this->module->requireStrings($this->path($path));
    }

    /**
     * @return array<mixed>
     *
     * @throws NotFoundException|FormatException|InvalidJsonException
     */
    public function requireArray(string $path): array
    {
        return $this->module->requireArray($this->path($path));
    }

    /**
     * A nested subtree; prefixes are joined with "/" and normalized (see {@see cleanPrefix()}).
     */
    public function subtree(string $prefix): self
    {
        $joined = $this->prefix === '' ? $prefix : $this->prefix . '/' . $prefix;

        return new self($this->module, self::cleanPrefix($joined));
    }

    /**
     * @return list<string>
     *
     * @throws InvalidJsonException
     */
    public function children(string $path): array
    {
        return $this->module->children($this->path($path));
    }

    /**
     * @throws InvalidJsonException|FormatException
     */
    public function getTree(string $path, ?int $maxDepth = null): mixed
    {
        return $this->module->getTree($this->path($path), $maxDepth);
    }

    /**
     * @param callable(string, ?string, ?string, bool): void $visitor
     *
     * @throws InvalidJsonException
     */
    public function walk(string $path, callable $visitor, ?int $maxDepth = null): void
    {
        $this->module->walk($this->path($path), $visitor, $maxDepth);
    }

    /**
     * Normalizes a prefix: drops a trailing slash, collapses "//",
     * resolves "." and ".."; "/" and "" become "" (the whole module).
     *
     * @internal
     */
    public static function cleanPrefix(string $prefix): string
    {
        $rooted = str_starts_with($prefix, '/');
        $segments = [];

        foreach (explode('/', $prefix) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments !== [] && $segments[count($segments) - 1] !== '..') {
                    array_pop($segments);
                    continue;
                }
                if ($rooted) {
                    continue;
                }
            }
            $segments[] = $segment;
        }

        if ($segments === []) {
            return '';
        }

        return ($rooted ? '/' : '') . implode('/', $segments);
    }
}
