<?php

declare(strict_types=1);

/*
 * Type inference of the typed getters, checked by PHPStan (assertType() fails the analysis on a mismatch).
 * Never executed: PHPUnit loads only *Test.php.
 */

namespace Onlineconf\Tests\Types;

use Onlineconf\Module;
use Onlineconf\Subtree;

use function PHPStan\Testing\assertType;

function moduleGetters(Module $module, ?string $maybe): void
{
    assertType('string', $module->getString('/p', 'd'));
    assertType('string|null', $module->getString('/p'));
    assertType('string|null', $module->getString('/p', null));
    assertType('string|null', $module->getString('/p', $maybe));
    assertType('int', $module->getInt('/p', 1));
    assertType('int|null', $module->getInt('/p'));
    assertType('float', $module->getFloat('/p', 1.0));
    assertType('float|null', $module->getFloat('/p'));
    assertType('bool', $module->getBool('/p', false));
    assertType('bool|null', $module->getBool('/p'));
    assertType('float', $module->getDuration('/p', 1.0));
    assertType('float|null', $module->getDuration('/p'));
    assertType('int', $module->getDurationMs('/p', 1));
    assertType('int|null', $module->getDurationMs('/p'));
    assertType('list<string>', $module->getStrings('/p', []));
    assertType('list<string>|null', $module->getStrings('/p'));
    assertType('array<mixed>', $module->getArray('/p', []));
    assertType('array<mixed>|null', $module->getArray('/p'));
}

function subtreeGetters(Subtree $subtree): void
{
    assertType('string', $subtree->getString('/p', 'd'));
    assertType('string|null', $subtree->getString('/p'));
    assertType('int', $subtree->getInt('/p', 1));
    assertType('int|null', $subtree->getInt('/p'));
    assertType('float', $subtree->getFloat('/p', 1.0));
    assertType('float|null', $subtree->getFloat('/p'));
    assertType('bool', $subtree->getBool('/p', false));
    assertType('bool|null', $subtree->getBool('/p'));
    assertType('float', $subtree->getDuration('/p', 1.0));
    assertType('float|null', $subtree->getDuration('/p'));
    assertType('int', $subtree->getDurationMs('/p', 1));
    assertType('int|null', $subtree->getDurationMs('/p'));
    assertType('list<string>', $subtree->getStrings('/p', []));
    assertType('list<string>|null', $subtree->getStrings('/p'));
    assertType('array<mixed>', $subtree->getArray('/p', []));
    assertType('array<mixed>|null', $subtree->getArray('/p'));
}
