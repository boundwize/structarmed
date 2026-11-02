<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

/**
 * One class-like type named by a member's signature. Its modifiers hold exactly
 * one visibility flag and one kind flag; rules decide which of them matter.
 */
final readonly class ClassSignature
{
    use PropertyUnserializeTrait;

    public const PUBLIC = 1;

    public const PROTECTED = 2;

    public const PRIVATE = 4;

    public const PARAMETER = 8;

    public const RETURN_TYPE = 16;

    public const PROPERTY = 32;

    public function __construct(
        public string $type,
        public int $modifiers,
        public int $line,
    ) {
    }

    public function isExposed(): bool
    {
        return ($this->modifiers & (self::PUBLIC | self::PROTECTED)) !== 0;
    }
}
