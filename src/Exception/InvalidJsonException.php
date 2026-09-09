<?php

declare(strict_types=1);

namespace Onlineconf\Exception;

/**
 * A value of type `j` (or a child list) contains invalid JSON. This is a delivery pipeline failure, so it is thrown even by getters that take a default.
 */
final class InvalidJsonException extends OnlineconfException
{
}
