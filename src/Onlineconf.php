<?php

declare(strict_types=1);

namespace Onlineconf;

use Onlineconf\Exception\OpenException;
use Onlineconf\Source\CdbSource;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Process-wide registry of modules: the same file always yields the same {@see Module}.
 *
 * Settings (directory, default module, logger, check interval) are read once, on the first
 * call to {@see module()}, and can be overridden before that with the setters.
 */
final class Onlineconf
{
    private static ?string $dir = null;

    private static ?string $module = null;

    private static ?Settings $settings = null;

    private static ?LoggerInterface $logger = null;

    private static int $checkInterval = Module::DEFAULT_CHECK_INTERVAL;

    /** @var array<string, Module> modules by resolved file path */
    private static array $modules = [];

    /**
     * Opens a module by name ("TREE" → "<dir>/TREE.cdb") or by file path, or returns the already opened one.
     * A disabled client (ONLINECONF_ENABLED=false) gives an empty module that touches no file.
     *
     * @throws OpenException
     */
    public static function module(?string $name = null): Module
    {
        $settings = self::settings();
        $file = $settings->fileName($name ?? $settings->module);
        // A disabled client touches no file, not even to resolve its real path
        $key = $settings->enabled ? realpath($file) : false;
        if ($key === false) {
            $key = $file;
        }

        return self::$modules[$key] ??= $settings->enabled
            ? new Module(new CdbSource($key), self::logger(), self::$checkInterval)
            : Module::fromSettings($settings, $file, self::logger(), self::$checkInterval);
    }

    public static function setDefaultDir(string $dir): void
    {
        self::$dir = $dir;
        self::$settings = null;
    }

    public static function setDefaultModule(string $module): void
    {
        self::$module = $module;
        self::$settings = null;
    }

    /**
     * Logger for modules opened afterwards; NullLogger by default.
     */
    public static function setLogger(LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    /**
     * Seconds between update checks for modules opened afterwards; {@see Module::DEFAULT_CHECK_INTERVAL} by default, 0 = check on every access.
     */
    public static function setCheckInterval(int $seconds): void
    {
        self::$checkInterval = $seconds;
    }

    /**
     * Resolved settings (see {@see Settings::resolve()}).
     */
    public static function settings(): Settings
    {
        return self::$settings ??= Settings::resolve(getenv(), self::$dir, self::$module, self::logger());
    }

    /**
     * Forgets opened modules and resolved settings; intended for tests.
     */
    public static function reset(): void
    {
        self::$modules = [];
        self::$settings = null;
        self::$dir = null;
        self::$module = null;
        self::$logger = null;
        self::$checkInterval = Module::DEFAULT_CHECK_INTERVAL;
    }

    private static function logger(): LoggerInterface
    {
        return self::$logger ??= new NullLogger();
    }
}
