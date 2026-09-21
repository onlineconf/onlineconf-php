<?php

declare(strict_types=1);

namespace Onlineconf\Source;

use Onlineconf\Json;
use Onlineconf\Source;

/**
 * In-memory source for tests of code that consumes OnlineConf.
 *
 * The constructor takes raw values (path → value with the leading type byte), exactly as they
 * are stored in CDB, and generates the child lists (the "<path>/" keys) for every path starting
 * with "/" itself; passing a child list is an error. {@see fromValues()} is the convenient form:
 * path → PHP value, where a string becomes an `s` value, an array is JSON-encoded into a `j` value
 * and null becomes an empty `s` value.
 */
final class ArraySource implements Source
{
    use ChangeTracking;

    /** @var array<string, string> path → raw value including the type byte, plus the generated child lists */
    private array $raw;

    /**
     * @param array<string, string> $raw path → raw value including the type byte, without child lists
     *
     * @throws \InvalidArgumentException when a child list ("<path>/") is passed
     */
    public function __construct(array $raw = [], private readonly string $name = 'array')
    {
        $this->raw = self::withChildLists($raw);
    }

    /**
     * @param array<string, string|int|float|bool|array<mixed>|null> $values path → PHP value
     */
    public static function fromValues(array $values, string $name = 'array'): self
    {
        return new self(self::rawValues($values), $name);
    }

    /**
     * Converts PHP values into raw OnlineConf values and generates the child lists,
     * i.e. produces exactly the set of keys onlineconf-updater writes into a CDB file.
     *
     * @param array<string, string|int|float|bool|array<mixed>|null> $values path → PHP value
     *
     * @return array<string, string> path → raw value including the type byte
     */
    public static function rawFromValues(array $values): array
    {
        return self::withChildLists(self::rawValues($values));
    }

    /**
     * @param array<string, string|int|float|bool|array<mixed>|null> $values path → PHP value
     *
     * @return array<string, string> path → raw value including the type byte, without child lists
     */
    private static function rawValues(array $values): array
    {
        $raw = [];
        foreach ($values as $path => $value) {
            $raw[$path] = match (true) {
                is_array($value) => 'j' . Json::encode($value),
                is_bool($value) => $value ? 's1' : 's0',
                default => 's' . $value,
            };
        }

        return $raw;
    }

    /**
     * Adds the child lists ("<path>/" → JSON array of child names) for the given raw values, producing
     * exactly the set of keys onlineconf-updater writes into a CDB file. Public for tools that rebuild
     * a module from its raw pairs: strip the existing lists, edit, then call this.
     *
     * @param array<string, string> $raw path → raw value including the type byte, without child lists
     *
     * @return array<string, string>
     *
     * @throws \InvalidArgumentException when $raw already contains a child list
     */
    public static function withChildLists(array $raw): array
    {
        /** @var array<string, array<string, true>> $children list path → child names */
        $children = [];

        foreach ($raw as $path => $_) {
            // PHP turns numeric array keys into integers; paths are strings
            $path = (string) $path;
            if (str_ends_with($path, '/')) {
                throw new \InvalidArgumentException(sprintf('child lists ("<path>/") are generated from the paths, "%s" must not be passed', $path));
            }
            if (!str_starts_with($path, '/')) {
                continue;
            }

            $parent = '';
            foreach (explode('/', substr($path, 1)) as $segment) {
                $children[$parent . '/'][$segment] = true;
                $parent .= '/' . $segment;
            }
        }

        foreach ($children as $listPath => $names) {
            // PHP turns numeric array keys into integers; child names are strings
            $list = array_map('strval', array_keys($names));
            sort($list, SORT_STRING);
            $raw[$listPath] = 'j' . Json::encode($list);
        }

        return $raw;
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

    /**
     * Replaces the data; the next {@see reloadIfChanged()} returns true and {@see version()} changes.
     *
     * @param array<string, string> $raw path → raw value including the type byte, without child lists
     *
     * @throws \InvalidArgumentException when a child list ("<path>/") is passed
     */
    public function replace(array $raw): void
    {
        $this->raw = self::withChildLists($raw);
        $this->markChanged();
    }

    /**
     * Same as {@see replace()}, but takes PHP values (see {@see fromValues()}).
     *
     * @param array<string, string|int|float|bool|array<mixed>|null> $values path → PHP value
     */
    public function replaceValues(array $values): void
    {
        $this->replace(self::rawValues($values));
    }
}
