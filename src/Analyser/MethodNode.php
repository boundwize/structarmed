<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

final class MethodNode
{
    public function __construct(
        public readonly string $name,
        public readonly string $visibility,   // public, protected, private
        public readonly bool $hasReturnType,
        public readonly bool $isStatic,
        public readonly int $paramCount,
        public readonly int $cyclomaticComplexity,
        public readonly int $lineCount,
    ) {}

    public function isPublic(): bool
    {
        return $this->visibility === 'public';
    }

    public function isConstructor(): bool
    {
        return $this->name === '__construct';
    }
}
