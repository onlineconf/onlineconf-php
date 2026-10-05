<?php

declare(strict_types=1);

namespace Onlineconf\Exception;

/**
 * A getter's default that does not read as its type — a programming or configuration error at the call site,
 * not a problem of the module, hence not an {@see OnlineconfException}.
 */
final class InvalidDefaultException extends \InvalidArgumentException
{
}
