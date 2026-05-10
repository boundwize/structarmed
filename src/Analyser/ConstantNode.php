<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

final readonly class ConstantNode
{
    public function __construct(
        public string $name,
        public int $line = 0,
    ) {
    }
}
