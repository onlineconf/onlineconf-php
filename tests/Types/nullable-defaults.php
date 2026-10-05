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

/**
 * @param string|null $env what env() gives: a string, or null when unset
 */
function moduleGetters(Module $module, ?string $maybe, ?string $env): void
{
    assertType('string', $module->getString('/p', 'd'));
    assertType('string|null', $module->getString('/p'));
    assertType('string|null', $module->getString('/p', null));
    assertType('string|null', $module->getString('/p', $maybe));

    assertType('int', $module->getInt('/p', 1));
    assertType('int', $module->getInt('/p', '80')); // a non-empty string parses or throws
    assertType('int|null', $module->getInt('/p'));
    assertType('int|null', $module->getInt('/p', ''));
    assertType('int|null', $module->getInt('/p', $env));
    assertType('float', $module->getFloat('/p', 1.0));
    assertType('float', $module->getFloat('/p', 1));
    assertType('float', $module->getFloat('/p', '2.5'));
    assertType('float|null', $module->getFloat('/p', $env));
    assertType('bool', $module->getBool('/p', false));
    assertType('bool', $module->getBool('/p', '1'));
    assertType('bool|null', $module->getBool('/p', $env));
    assertType('float', $module->getDuration('/p', 1.0));
    assertType('float', $module->getDuration('/p', '1m'));
    assertType('float|null', $module->getDuration('/p'));
    assertType('int', $module->getDurationMs('/p', 1));
    assertType('int', $module->getDurationMs('/p', '1s'));
    assertType('int|null', $module->getDurationMs('/p', $env));
    assertType('list<string>', $module->getStrings('/p', []));
    assertType('list<string>', $module->getStrings('/p', 'a,b'));
    assertType('list<string>|null', $module->getStrings('/p', $env));
    assertType('array<mixed>', $module->getArray('/p', []));
    assertType('array<mixed>', $module->getArray('/p', '{"a":1}'));
    assertType('array<mixed>|null', $module->getArray('/p', $env));
}

function subtreeGetters(Subtree $subtree, ?string $env): void
{
    assertType('string', $subtree->getString('/p', 'd'));
    assertType('string|null', $subtree->getString('/p'));
    assertType('int', $subtree->getInt('/p', '80'));
    assertType('int|null', $subtree->getInt('/p', $env));
    assertType('float', $subtree->getFloat('/p', 1.0));
    assertType('float|null', $subtree->getFloat('/p'));
    assertType('bool', $subtree->getBool('/p', false));
    assertType('bool|null', $subtree->getBool('/p', $env));
    assertType('float', $subtree->getDuration('/p', '1m'));
    assertType('float|null', $subtree->getDuration('/p'));
    assertType('int', $subtree->getDurationMs('/p', 1));
    assertType('int|null', $subtree->getDurationMs('/p'));
    assertType('list<string>', $subtree->getStrings('/p', 'a'));
    assertType('list<string>|null', $subtree->getStrings('/p'));
    assertType('array<mixed>', $subtree->getArray('/p', []));
    assertType('array<mixed>|null', $subtree->getArray('/p', $env));
}
