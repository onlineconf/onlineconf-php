<?php

declare(strict_types=1);

namespace Onlineconf\Source;

/**
 * Change bookkeeping of the in-memory sources: a generation counter for {@see \Onlineconf\Source::version()}
 * and a flag consumed by {@see \Onlineconf\Source::reloadIfChanged()}.
 *
 * @internal
 */
trait ChangeTracking
{
    private int $generation = 0;

    private bool $changed = false;

    private function markChanged(): void
    {
        ++$this->generation;
        $this->changed = true;
    }

    /**
     * Whether the data changed since the last call.
     */
    private function consumeChanged(): bool
    {
        $changed = $this->changed;
        $this->changed = false;

        return $changed;
    }
}
