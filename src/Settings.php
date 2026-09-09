<?php

declare(strict_types=1);

namespace Onlineconf;

use Psr\Log\LoggerInterface;

/**
 * Resolved client settings: the directory with module files and the default module name.
 *
 * Resolution order (highest priority first):
 *  1. explicit values ({@see Onlineconf::setDefaultDir()}, {@see Onlineconf::setDefaultModule()});
 *  2. environment variables ONLINECONF_DIR and ONLINECONF_CONFIG (path of the client config file, key data_dir);
 *  3. CDB_CONFIG_FILE: path of the default module file, gives both the directory and the module name;
 *  4. the default client config file /usr/local/etc/onlineconf.yaml: key data_dir;
 *  5. built-in defaults: /usr/local/etc/onlineconf and TREE.
 *
 * Empty environment variables count as unset; a missing or unreadable client config file (or one without
 * data_dir) is skipped, so the next level applies.
 */
final class Settings
{
    public const DEFAULT_DIR = '/usr/local/etc/onlineconf';
    public const DEFAULT_MODULE = 'TREE';
    public const DEFAULT_CONFIG_FILE = '/usr/local/etc/onlineconf.yaml';

    public function __construct(public readonly string $dir, public readonly string $module)
    {
    }

    /**
     * @param array<string, string> $env environment variables (getenv())
     */
    public static function resolve(array $env, ?string $dir, ?string $module, LoggerInterface $logger): self
    {
        $dir ??= self::nonEmpty($env['ONLINECONF_DIR'] ?? '');

        $configFile = self::nonEmpty($env['ONLINECONF_CONFIG'] ?? '');
        if ($dir === null && $configFile !== null) {
            $dir = self::dirFromClientConfig($configFile, $logger);
        }

        $cdbFile = self::nonEmpty($env['CDB_CONFIG_FILE'] ?? '');
        if ($cdbFile !== null) {
            $dir ??= dirname($cdbFile);
            $module ??= basename($cdbFile, '.cdb');
        }

        $dir ??= self::dirFromClientConfig(self::DEFAULT_CONFIG_FILE, $logger) ?? self::DEFAULT_DIR;

        return new self(rtrim($dir, '/'), $module ?? self::DEFAULT_MODULE);
    }

    /**
     * Resolves a module name to a file path: a name without "/" is a file in the directory,
     * a name with "/" is a path; ".cdb" is appended when there is no extension.
     */
    public function fileName(string $name): string
    {
        $file = str_contains($name, '/') ? $name : $this->dir . '/' . $name;

        return pathinfo($file, PATHINFO_EXTENSION) === '' ? $file . '.cdb' : $file;
    }

    private static function nonEmpty(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /**
     * data_dir from the client config file; null when the file is missing/unreadable or has no data_dir.
     */
    private static function dirFromClientConfig(string $file, LoggerInterface $logger): ?string
    {
        $content = is_readable($file) ? file_get_contents($file) : false;
        if ($content === false) {
            $logger->debug(sprintf('onlineconf: client config %s is not readable, skipped', $file));

            return null;
        }

        $config = self::parseFlatYaml($content, $file, $logger);

        if (($config['enable_cdb_client'] ?? '1') === '0') {
            $logger->warning(sprintf('onlineconf: %s: enable_cdb_client is 0, but the text format is not supported, using CDB', $file));
        }

        return self::nonEmpty($config['data_dir'] ?? '');
    }

    /**
     * Parses a flat "key: value" YAML file (comments, quotes and blank lines are supported;
     * nested structures and lists are reported and ignored).
     *
     * @return array<string, string>
     */
    private static function parseFlatYaml(string $content, string $file, LoggerInterface $logger): array
    {
        $config = [];

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $content)) as $number => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed[0] === '#' || $trimmed === '---' || $trimmed === '...') {
                continue;
            }

            if ($line[0] === ' ' || $line[0] === "\t" || $trimmed[0] === '-'
                || preg_match('/^([^\s:#][^:#]*?)\s*:(?:\s+(.*))?$/', $trimmed, $match) !== 1 || !isset($match[2])) {
                $logger->warning(sprintf('onlineconf: %s:%d: unsupported YAML structure, line ignored', $file, $number + 1));
                continue;
            }

            $config[$match[1]] = self::yamlScalar($match[2]);
        }

        return $config;
    }

    private static function yamlScalar(string $value): string
    {
        if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"/', $value, $match) === 1) {
            return stripcslashes($match[1]);
        }
        if (preg_match("/^'((?:[^']|'')*)'/", $value, $match) === 1) {
            return str_replace("''", "'", $match[1]);
        }

        return trim(preg_replace('/\s+#.*$/', '', $value) ?? $value);
    }
}
