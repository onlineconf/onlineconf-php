<?php

declare(strict_types=1);

namespace Onlineconf;

/**
 * JSON encoding as OnlineConf stores it: compact, UTF-8 and "/" unescaped, errors thrown.
 *
 * @internal
 */
final class Json
{
    public const FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * @throws \JsonException
     */
    public static function encode(mixed $value, int $flags = 0): string
    {
        return json_encode($value, $flags | self::FLAGS);
    }
}
