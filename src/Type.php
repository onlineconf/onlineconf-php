<?php

declare(strict_types=1);

namespace Onlineconf;

/**
 * Requested value type; used as the decoded value cache key.
 *
 * @internal
 */
enum Type
{
    case String;
    case Int;
    case Float;
    case Bool;
    case Duration;
    case DurationMs;
    case Strings;
    case Array;
    case Mixed;
}
