<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

/**
 * Without __unserialize(), unserialize() builds a property hash table on every
 * object and keeps it, which roughly doubles what a node graph costs. Worker
 * results reach the coordinator through serialize(), so on a large project
 * this halves what the coordinator holds.
 */
trait PropertyUnserializeTrait
{
    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        foreach ($data as $property => $value) {
            $this->{$property} = $value;
        }
    }
}
