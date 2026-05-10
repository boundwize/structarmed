<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule;

use function sprintf;

final readonly class RuleViolation
{
    public function __construct(
        public string $message,
        public string $file,
        public int $line,
        public string $className,
        public ?string $layer = null,
        public string $ruleKey = '',
    ) {
    }

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
            'rule'    => $this->ruleKey,
            'message' => $this->message,
            'file'    => $this->file,
            'line'    => $this->line,
            'class'   => $this->className,
            'layer'   => $this->layer,
        ];
    }
}
