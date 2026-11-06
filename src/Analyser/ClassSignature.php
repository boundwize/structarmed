<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

/**
 * One class-like type named by a member's signature. Its name and kind tell
 * which member: the type of a property or parameter, or a method's return type.
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
    ) {
    }

    public function isExposed(): bool
    {
        return $this->visibility !== 'private';
    }
}
