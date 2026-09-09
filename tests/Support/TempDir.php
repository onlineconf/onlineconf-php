<?php

declare(strict_types=1);

namespace Onlineconf\Tests\Support;

/**
 * Temporary paths for tests; unique per process and call, never colliding with each other.
 */
final class TempDir
{
    /**
     * A fresh path in the system temp directory (nothing is created), e.g. `TempDir::file('.cdb')`.
     */
    public static function file(string $suffix = ''): string
    {
        return self::path('oc') . $suffix;
    }

    /**
     * A fresh, empty directory.
     */
    public static function create(string $prefix): string
    {
        $dir = self::path($prefix);
        mkdir($dir);

        return $dir;
    }

    public static function remove(string $dir): void
    {
        foreach (new \FilesystemIterator($dir) as $file) {
            unlink((string) $file);
        }
        rmdir($dir);
    }

    private static function path(string $prefix): string
    {
        return sys_get_temp_dir() . '/' . $prefix . '-' . getmypid() . '-' . bin2hex(random_bytes(4));
    }
}
