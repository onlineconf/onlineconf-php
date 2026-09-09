<?php

declare(strict_types=1);

namespace Onlineconf\Tests\Support;

use Psr\Log\AbstractLogger;

final class TestLogger extends AbstractLogger
{
    /** @var list<array{string, string}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [is_scalar($level) ? (string) $level : 'unknown', (string) $message];
    }

    /**
     * @return list<string>
     */
    public function messages(?string $level = null): array
    {
        $messages = [];
        foreach ($this->records as [$recordLevel, $message]) {
            if ($level === null || $recordLevel === $level) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * Levels of all records, in order.
     *
     * @return list<string>
     */
    public function levels(): array
    {
        return array_column($this->records, 0);
    }

    /**
     * Messages without the "onlineconf: <module>:<path>: " prefix.
     *
     * @return list<string>
     */
    public function reasons(?string $level = null): array
    {
        return array_map(static fn (string $m): string => substr($m, (int) strrpos($m, ': ') + 2), $this->messages($level));
    }

    public function count(?string $level = null): int
    {
        return count($this->messages($level));
    }

    public function clear(): void
    {
        $this->records = [];
    }
}
