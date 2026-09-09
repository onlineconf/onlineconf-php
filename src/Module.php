<?php

declare(strict_types=1);

namespace Onlineconf;

use Onlineconf\Exception\FormatException;
use Onlineconf\Exception\InvalidJsonException;
use Onlineconf\Exception\NotFoundException;
use Onlineconf\Exception\OpenException;
use Onlineconf\Exception\ParseException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * A configuration module: typed access to the values of a {@see Source}.
 *
 * Getters with a default (`getX($path, $default)`) never throw, except for
 * {@see InvalidJsonException}; every problem is logged at "warning" level and the
 * default is returned. Strict getters (`requireX($path)`) throw instead.
 *
 * Paths are opaque strings: getters do not parse them and do not require a leading slash,
 * so modules using dot-notation keys ("db.host") are readable too. Only {@see subtree()},
 * {@see children()}, {@see getTree()} and {@see walk()} know about "/".
 */
final class Module
{
    /** Default number of seconds between stat() checks for updates. */
    public const DEFAULT_CHECK_INTERVAL = 5;

    private readonly LoggerInterface $logger;

    /** @var array<string, ?string> raw values (with the type byte) by path; null = key does not exist */
    private array $raw = [];

    /** @var array<string, array<string, mixed>> decoded values by requested type and path */
    private array $decoded = [];

    /** @var array<string, list<string>> child lists by list path ("<path>/") */
    private array $childLists = [];

    private float $lastCheck;

    private bool $childListsWarned = false;

    /**
     * @param int $checkInterval seconds between stat() checks for updates; 0 = check on every access
     */
    public function __construct(
        private readonly Source $source,
        ?LoggerInterface $logger = null,
        private readonly int $checkInterval = self::DEFAULT_CHECK_INTERVAL,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->lastCheck = microtime(true);
    }

    public function name(): string
    {
        return $this->source->name();
    }

    /**
     * Identifier of the loaded data (for CDB: "inode:mtime:size"); changes on every reload.
     */
    public function version(): string
    {
        return $this->source->version();
    }

    /**
     * Checks for updates immediately, bypassing the check interval; returns true if the data was reloaded.
     */
    public function checkForUpdates(): bool
    {
        $this->lastCheck = microtime(true);

        return $this->reload();
    }

    /**
     * Raw value as stored, including the leading type byte (`s` string, `j` JSON); null when the key does not exist.
     * For tools that need the stored form; application code should use the typed getters.
     */
    public function getRaw(string $path): ?string
    {
        $this->maybeReload();

        return $this->raw($path);
    }

    public function has(string $path): bool
    {
        $this->maybeReload();

        return $this->raw($path) !== null;
    }

    public function getString(string $path, string $default): string
    {
        /** @var string */
        return $this->lookup($path, Type::String, $default, false);
    }

    public function getInt(string $path, int $default): int
    {
        /** @var int */
        return $this->lookup($path, Type::Int, $default, false);
    }

    public function getFloat(string $path, float $default): float
    {
        /** @var float */
        return $this->lookup($path, Type::Float, $default, false);
    }

    public function getBool(string $path, bool $default): bool
    {
        /** @var bool */
        return $this->lookup($path, Type::Bool, $default, false);
    }

    /**
     * Duration in seconds (see {@see Duration::parse()}).
     */
    public function getDuration(string $path, float $default): float
    {
        /** @var float */
        return $this->lookup($path, Type::Duration, $default, false);
    }

    /**
     * Duration in milliseconds, rounded to the nearest integer.
     */
    public function getDurationMs(string $path, int $default): int
    {
        /** @var int */
        return $this->lookup($path, Type::DurationMs, $default, false);
    }

    /**
     * A comma-separated `s` value ("a, b,c") or a `j` array of strings.
     *
     * @param list<string> $default
     *
     * @return list<string>
     */
    public function getStrings(string $path, array $default): array
    {
        /** @var list<string> */
        return $this->lookup($path, Type::Strings, $default, false);
    }

    /**
     * A `j` value decoded with json_decode(..., true).
     *
     * @param array<mixed> $default
     *
     * @return array<mixed>
     *
     * @throws InvalidJsonException
     */
    public function getArray(string $path, array $default): array
    {
        /** @var array<mixed> */
        return $this->lookup($path, Type::Array, $default, false);
    }

    /**
     * Untyped value: `s` → string, `j` → decoded JSON (array or scalar).
     *
     * @throws InvalidJsonException
     */
    public function get(string $path, mixed $default): mixed
    {
        return $this->lookup($path, Type::Mixed, $default, false);
    }

    /**
     * Untyped value like {@see get()}, but the key must exist and have a known format.
     *
     * @throws NotFoundException|FormatException|InvalidJsonException
     */
    public function require(string $path): mixed
    {
        return $this->lookup($path, Type::Mixed, null, true);
    }

    /**
     * @throws NotFoundException|FormatException
     */
    public function requireString(string $path): string
    {
        /** @var string */
        return $this->lookup($path, Type::String, null, true);
    }

    /**
     * @throws NotFoundException|FormatException|ParseException
     */
    public function requireInt(string $path): int
    {
        /** @var int */
        return $this->lookup($path, Type::Int, null, true);
    }

    /**
     * @throws NotFoundException|FormatException|ParseException
     */
    public function requireFloat(string $path): float
    {
        /** @var float */
        return $this->lookup($path, Type::Float, null, true);
    }

    /**
     * @throws NotFoundException|FormatException
     */
    public function requireBool(string $path): bool
    {
        /** @var bool */
        return $this->lookup($path, Type::Bool, null, true);
    }

    /**
     * @throws NotFoundException|FormatException|ParseException
     */
    public function requireDuration(string $path): float
    {
        /** @var float */
        return $this->lookup($path, Type::Duration, null, true);
    }

    /**
     * @throws NotFoundException|FormatException|ParseException
     */
    public function requireDurationMs(string $path): int
    {
        /** @var int */
        return $this->lookup($path, Type::DurationMs, null, true);
    }

    /**
     * @return list<string>
     *
     * @throws NotFoundException|FormatException|InvalidJsonException
     */
    public function requireStrings(string $path): array
    {
        /** @var list<string> */
        return $this->lookup($path, Type::Strings, null, true);
    }

    /**
     * @return array<mixed>
     *
     * @throws NotFoundException|FormatException|InvalidJsonException
     */
    public function requireArray(string $path): array
    {
        /** @var array<mixed> */
        return $this->lookup($path, Type::Array, null, true);
    }

    /**
     * A view of the module restricted to a prefix; the prefix is normalized (see {@see Subtree::cleanPrefix()}).
     */
    public function subtree(string $prefix): Subtree
    {
        return new Subtree($this, Subtree::cleanPrefix($prefix));
    }

    /**
     * Names of the direct children of a node, taken from the child list key "<path>/".
     *
     * Returns an empty list when the node has no child list.
     *
     * @return list<string>
     *
     * @throws InvalidJsonException
     */
    public function children(string $path): array
    {
        $this->maybeReload();

        return $this->childList(self::nodePath($path));
    }

    /**
     * Reads a subtree into nested arrays.
     *
     * A leaf becomes its value (`s` → string, `j` → decoded JSON); a node with children becomes an
     * array of its children keyed by name, with the node's own value (if any) under the key "".
     * A node without value and children becomes null. Nodes at `$maxDepth` are treated as leaves.
     *
     * Child names are PHP array keys, so numeric names ("0", "1") become integer keys: `$tree['0']` and
     * `$tree[0]` are the same element, but foreach yields ints, and a node whose children are named
     * "0".."n-1" is a list for json_encode() (printed as a JSON array without the names).
     *
     * @throws InvalidJsonException|FormatException
     */
    public function getTree(string $path, ?int $maxDepth = null): mixed
    {
        $this->maybeReload();

        return $this->buildTree(self::nodePath($path), $maxDepth, 0);
    }

    /**
     * Depth-first traversal of a subtree with raw values.
     *
     * The visitor receives the node path without a trailing slash ("" for the root), the type byte and
     * the raw value without the type byte (both null when the node has no value of its own) and whether
     * the node has children.
     *
     * @param callable(string, ?string, ?string, bool): void $visitor
     *
     * @throws InvalidJsonException
     */
    public function walk(string $path, callable $visitor, ?int $maxDepth = null): void
    {
        $this->maybeReload();
        $this->walkNode(self::nodePath($path), $visitor, $maxDepth, 0);
    }

    private function maybeReload(): void
    {
        if ($this->checkInterval > 0) {
            $now = microtime(true);
            if ($now - $this->lastCheck < $this->checkInterval) {
                return;
            }
            $this->lastCheck = $now;
        }

        $this->reload();
    }

    private function reload(): bool
    {
        try {
            $changed = $this->source->reloadIfChanged();
        } catch (OpenException $e) {
            $this->logger->error(sprintf('onlineconf: %s: reload failed, keeping old data: %s', $this->name(), $e->getMessage()));

            return false;
        }

        if ($changed) {
            $this->raw = [];
            $this->decoded = [];
            $this->childLists = [];
            $this->logger->info(sprintf('onlineconf: %s: reloaded, version %s', $this->name(), $this->version()));
        }

        return $changed;
    }

    private function raw(string $path): ?string
    {
        if (!array_key_exists($path, $this->raw)) {
            $raw = $this->source->getRaw($path);
            $this->raw[$path] = $raw === '' ? null : $raw;
        }

        return $this->raw[$path];
    }

    /**
     * @throws NotFoundException|FormatException|ParseException|InvalidJsonException
     */
    private function lookup(string $path, Type $type, mixed $default, bool $strict): mixed
    {
        $this->maybeReload();

        if (array_key_exists($path, $this->decoded[$type->name] ?? [])) {
            return $this->decoded[$type->name][$path];
        }

        $raw = $this->raw($path);
        if ($raw === null) {
            if ($strict) {
                throw new NotFoundException($this->message($path, 'key not found'));
            }

            return $default;
        }

        try {
            return $this->decodeCached($path, $type, $raw);
        } catch (FormatException|ParseException $e) {
            if ($strict) {
                throw $e;
            }
            $this->logger->warning('onlineconf: ' . $e->getMessage());

            return $default;
        }
    }

    /**
     * decode() with the result stored in the per-type cache (dropped on reload).
     *
     * @throws FormatException|ParseException|InvalidJsonException
     */
    private function decodeCached(string $path, Type $type, string $raw): mixed
    {
        return $this->decoded[$type->name][$path] ??= $this->decode($path, $type, $raw);
    }

    /**
     * @throws FormatException|ParseException|InvalidJsonException
     */
    private function decode(string $path, Type $type, string $raw): mixed
    {
        $format = $raw[0];
        $data = substr($raw, 1);

        if ($type === Type::Mixed) {
            return $format === 's' ? $data : $this->decodeJson($path, $format, $data);
        }
        if ($type === Type::Array) {
            $value = $this->decodeJson($path, $format, $data);
            if (!is_array($value)) {
                throw new FormatException($this->message($path, 'JSON value is not an array or object'));
            }

            return $value;
        }
        if ($type === Type::Strings && $format === 'j') {
            return $this->decodeJsonStrings($path, $format, $data);
        }
        if ($format !== 's') {
            throw new FormatException($this->message($path, 'format is not a string'));
        }

        return match ($type) {
            Type::String => $data,
            Type::Int => $this->parseInt($path, $data),
            Type::Float => $this->parseFloat($path, $data),
            Type::Bool => $data !== '' && $data !== '0',
            Type::Duration => $this->parseDuration($path, $data),
            Type::DurationMs => (int) round($this->parseDuration($path, $data) * 1000),
            Type::Strings => array_values(array_filter(array_map('trim', explode(',', $data)), static fn (string $s): bool => $s !== '')),
        };
    }

    /**
     * @return list<string>
     *
     * @throws FormatException|InvalidJsonException
     */
    private function decodeJsonStrings(string $path, string $format, string $data): array
    {
        $value = $this->decodeJson($path, $format, $data);
        if (!is_array($value) || !array_is_list($value) || $value !== array_filter($value, 'is_string')) {
            throw new FormatException($this->message($path, 'JSON value is not an array of strings'));
        }

        return $value;
    }

    /**
     * @throws FormatException|InvalidJsonException
     */
    private function decodeJson(string $path, string $format, string $data): mixed
    {
        if ($format !== 'j') {
            throw new FormatException($this->message($path, $format === 's' ? 'format is not JSON' : sprintf("unexpected format '%s'", $format)));
        }

        $value = json_decode($data, true, 512);
        if ($value === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidJsonException($this->message($path, 'invalid JSON: ' . json_last_error_msg()));
        }

        return $value;
    }

    /**
     * @throws ParseException
     */
    private function parseInt(string $path, string $data): int
    {
        if (preg_match('/^[+-]?0*(\d+)$/D', $data, $match) !== 1) {
            throw new ParseException($this->message($path, sprintf('"%s" is not an integer', $data)));
        }

        $value = (int) $data;
        if (ltrim((string) $value, '-') !== $match[1]) {
            throw new ParseException($this->message($path, sprintf('"%s" is out of integer range', $data)));
        }

        return $value;
    }

    /**
     * @throws ParseException
     */
    private function parseFloat(string $path, string $data): float
    {
        if (!is_numeric($data) || trim($data) !== $data) {
            throw new ParseException($this->message($path, sprintf('"%s" is not a number', $data)));
        }

        return (float) $data;
    }

    /**
     * @throws ParseException
     */
    private function parseDuration(string $path, string $data): float
    {
        try {
            return Duration::parse($data);
        } catch (ParseException $e) {
            throw new ParseException($this->message($path, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @return list<string>
     *
     * @throws InvalidJsonException
     */
    private function childList(string $node): array
    {
        $listPath = $node . '/';

        return $this->childLists[$listPath] ??= $this->decodeChildList($listPath);
    }

    /**
     * @return list<string>
     *
     * @throws InvalidJsonException
     */
    private function decodeChildList(string $listPath): array
    {
        $raw = $this->raw($listPath);

        if ($raw === null) {
            if (!$this->childListsWarned && $this->raw('/') === null) {
                $this->childListsWarned = true;
                $this->logger->warning(sprintf('onlineconf: %s: child lists are not available (no "/" key); enable child_lists in onlineconf-updater', $this->name()));
            }

            return [];
        }

        try {
            return $this->decodeJsonStrings($listPath, $raw[0], substr($raw, 1));
        } catch (FormatException $e) {
            $this->logger->warning('onlineconf: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * @return array{?string, list<string>} raw value with the type byte (null when the node has none), children
     *
     * @throws InvalidJsonException
     */
    private function readNode(string $node, ?int $maxDepth, int $depth): array
    {
        $raw = $this->raw($node);
        $children = $maxDepth !== null && $depth >= $maxDepth ? [] : $this->childList($node);

        return [$raw, $children];
    }

    /**
     * @param callable(string, ?string, ?string, bool): void $visitor
     *
     * @throws InvalidJsonException
     */
    private function walkNode(string $node, callable $visitor, ?int $maxDepth, int $depth): void
    {
        [$raw, $children] = $this->readNode($node, $maxDepth, $depth);
        $visitor($node, $raw === null ? null : $raw[0], $raw === null ? null : substr($raw, 1), $children !== []);

        foreach ($children as $name) {
            $this->walkNode($node . '/' . $name, $visitor, $maxDepth, $depth + 1);
        }
    }

    /**
     * @throws InvalidJsonException|FormatException
     */
    private function buildTree(string $node, ?int $maxDepth, int $depth): mixed
    {
        [$raw, $children] = $this->readNode($node, $maxDepth, $depth);
        $value = $raw === null ? null : $this->decodeCached($node, Type::Mixed, $raw);

        if ($children === []) {
            return $value;
        }

        $tree = $value === null ? [] : ['' => $value];
        foreach ($children as $name) {
            $tree[$name] = $this->buildTree($node . '/' . $name, $maxDepth, $depth + 1);
        }

        return $tree;
    }

    /**
     * Normalizes the argument of children()/getTree()/walk() to the internal node form: no trailing slash,
     * the root ("" or "/") is "". The child-list key is then `$node . '/'` and a child is `$node . '/' . $name`.
     */
    private static function nodePath(string $path): string
    {
        return rtrim($path, '/');
    }

    private function message(string $path, string $text): string
    {
        return sprintf('%s:%s: %s', $this->name(), $path, $text);
    }
}
