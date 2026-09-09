<?php

declare(strict_types=1);

namespace Onlineconf\Exception;

/**
 * The value exists, but its type byte does not match the requested type (for example, a JSON value was requested as a string), or a JSON value has an unexpected shape.
 */
final class FormatException extends OnlineconfException
{
}
