<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

final readonly class PropertyNode
{
    public function __construct(
        public string $name,
        public string $visibility,
        public bool $hasExplicitVisibility,
        public int $line = 0,
    ) {
    }
}
