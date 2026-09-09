<?php

declare(strict_types=1);

namespace Onlineconf\Tests;

use Onlineconf\Module;
use Onlineconf\Source\CdbSource;
use Onlineconf\Tests\Support\Fixtures;
use Onlineconf\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The committed sample modules must stay in sync with the fixtures they are built from.
 */
final class ExamplesTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function modules(): iterable
    {
        foreach (array_keys(Fixtures::modules()) as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('modules')]
    public function testSampleMatchesFixture(string $name): void
    {
        $committed = dirname(__DIR__) . '/examples/onlineconf/' . $name;
        $generated = TempDir::file();
        $raw = Fixtures::build($name, $generated);
        $hint = 'run "php examples/build.php" to refresh examples/onlineconf/';

        self::assertSame(file_get_contents($generated . '.cdb'), file_get_contents($committed . '.cdb'), $hint);
        self::assertSame(file_get_contents($generated . '.conf'), file_get_contents($committed . '.conf'), $hint);
        unlink($generated . '.cdb');
        unlink($generated . '.conf');

        $conf = (string) file_get_contents($committed . '.conf');
        self::assertStringStartsWith('# This file is generated', $conf);
        self::assertStringContainsString("\n#! Name {$name}\n", $conf);
        self::assertStringEndsWith("\n#EOF", $conf);
        self::assertSame(count($raw), preg_match_all('/^[^#\n][^\n]*$/m', $conf), 'one line per key');

        $module = new Module(new CdbSource($committed . '.cdb'));

        foreach (Fixtures::modules()[$name] as $path => $value) {
            self::assertTrue($module->has($path), $path);
            self::assertSame(is_array($value) ? $value : (string) $value, $module->get($path, null) ?? '', $path);
        }
    }
}
