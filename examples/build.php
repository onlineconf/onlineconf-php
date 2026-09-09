<?php

declare(strict_types=1);

/*
 * Rebuilds the sample modules in examples/onlineconf/ from the test fixtures.
 * Usage: php examples/build.php   (after composer install)
 */

use Onlineconf\Tests\Support\Fixtures;

require __DIR__ . '/../vendor/autoload.php';

foreach (Fixtures::modules() as $name => $values) {
    $file = __DIR__ . '/onlineconf/' . $name;
    $raw = Fixtures::build($name, $file);
    printf("%s.cdb + .conf: %d keys (%d child lists), %d bytes\n", $file, count($values), count($raw) - count($values), (int) filesize($file . '.cdb'));
}
