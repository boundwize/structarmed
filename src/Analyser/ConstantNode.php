<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

final readonly class ConstantNode
{
    use PropertyUnserializeTrait;

    public function __construct(
        public string $name,
        public string $visibility = 'public',
        public bool $hasExplicitVisibility = false,
        public int $line = 0,
    ) {
    }
}
