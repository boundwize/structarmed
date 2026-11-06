<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

/**
 * One class-like type named by a member's signature. Its name and kind tell
 * which member: the type of a property or parameter, or a method's return type.
 * A property declared by constructor promotion, and the parameter a private one
 * is also recorded as, are flagged as promoted.
 */
final readonly class ClassSignature
{
    use PropertyUnserializeTrait;

    public const PROPERTY = 'property';

    public const PARAM = 'param';

    public const METHOD = 'method';

    public function __construct(
        public string $type,
        public string $name,
        public string $kind,
        public string $visibility,
        public int $line,
        public bool $isPromoted = false,
    ) {
    }

    public function isExposed(): bool
    {
        return $this->visibility !== 'private';
    }
}
