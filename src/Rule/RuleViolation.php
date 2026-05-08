<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule;

final class RuleViolation
{
    public function __construct(
        public readonly string $ruleKey,
        public readonly string $message,
        public readonly string $file,
        public readonly int $line,
        public readonly string $className,
        public readonly ?string $layer = null,
    ) {}

    public function toString(): string
    {
        return sprintf(
            '[%s] %s in %s:%d',
            $this->ruleKey,
            $this->message,
            $this->file,
            $this->line
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'rule'      => $this->ruleKey,
            'message'   => $this->message,
            'file'      => $this->file,
            'line'      => $this->line,
            'class'     => $this->className,
            'layer'     => $this->layer,
        ];
    }
}
