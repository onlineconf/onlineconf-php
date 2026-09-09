<?php

declare(strict_types=1);

namespace Onlineconf\Exception;

/**
 * The value is a string, but it cannot be parsed as the requested type (int, float or duration).
 */
final class ParseException extends OnlineconfException
{
}
