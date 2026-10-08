<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

/**
 * Assigns unserialized data straight into the declared properties.
 *
 * Without __unserialize(), unserialize() writes every property through the
 * object's property hash table, which it builds and then keeps for the
 * object's lifetime: about 1.7 KB for a ten-property node against 350 bytes
 * for the declared slots alone. The analysis-node graph crosses from the
 * parallel workers to the coordinator through serialize(), so on a large
 * project that table costs the coordinator more than the graph itself.
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
