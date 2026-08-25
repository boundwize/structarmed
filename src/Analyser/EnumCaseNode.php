<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

final readonly class EnumCaseNode
{
    /**
     * @param int|string|null $value Backing value of the case, or null for a pure enum
     *                               case or a value that cannot be resolved statically
     */
    public function __construct(
        public string $name,
        public int $line = 0,
        public int|string|null $value = null,
    ) {
    }

    public function isBacked(): bool
    {
        return $this->value !== null;
    }
}
