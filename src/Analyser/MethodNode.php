<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use function strcasecmp;

final readonly class MethodNode
{
    public function __construct(
        public string $name,
        public string $visibility, // public, protected, private
        public bool $hasReturnType,
        public bool $isStatic,
        public int $paramCount,
        public int $cyclomaticComplexity,
        public int $lineCount,
        public bool $hasExplicitVisibility = false,
        public int $line = 0,
        public bool $isMagic = false,
    ) {
    }

    public function isPublic(): bool
    {
        return $this->visibility === 'public';
    }

    public function isConstructor(): bool
    {
        return strcasecmp($this->name, '__construct') === 0;
    }

    public function isDestructor(): bool
    {
        return strcasecmp($this->name, '__destruct') === 0;
    }
}
