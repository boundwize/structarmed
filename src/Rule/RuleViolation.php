<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule;

use function sprintf;

final class RuleViolation
{
    public function __construct(
        public readonly string $message,
        public readonly string $file,
        public readonly int $line,
        public readonly string $className,
        public readonly ?string $layer = null,
        public string $ruleKey = '',
        public bool $fixable = false,
        public readonly ?string $methodName = null,
        public readonly ?string $constantName = null,
        public readonly ?string $propertyName = null,
        public readonly ?string $functionName = null,
        public readonly ?string $numericLiteral = null,
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
        $data = [
            'rule'    => $this->ruleKey,
            'message' => $this->message,
            'file'    => $this->file,
            'line'    => $this->line,
            'class'   => $this->className,
            'layer'   => $this->layer,
        ];

        if ($this->fixable) {
            $data['fixable'] = true;
        }

        if ($this->methodName !== null) {
            $data['method'] = $this->methodName;
        }

        if ($this->constantName !== null) {
            $data['constant'] = $this->constantName;
        }

        if ($this->propertyName !== null) {
            $data['property'] = $this->propertyName;
        }

        if ($this->functionName !== null) {
            $data['function'] = $this->functionName;
        }

        if ($this->numericLiteral !== null) {
            $data['numericLiteral'] = $this->numericLiteral;
        }

        return $data;
    }
}
