<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function sprintf;

final readonly class ApiMayNotBeInternalRule implements RuleInterface
{
    public function __construct(
        private string $layer,
    ) {
    }

    public function appliesTo(ClassNode $classNode): bool
    {
        return $classNode->isApi && $classNode->isInLayer($this->layer);
    }

    public function evaluate(ClassNode $classNode): ?RuleViolation
    {
        if (! $classNode->isInternal) {
            return null;
        }

        return new RuleViolation(
            message:   sprintf(
                '%s [%s] must not be marked both @api and @internal',
                $classNode->getType(),
                $classNode->className
            ),
            file:      $classNode->file,
            line:      $classNode->line,
            className: $classNode->className,
            layer:     $classNode->layer,
        );
    }
}
