<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Usage;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function sprintf;

final readonly class MayNotUseConstantRule implements RuleInterface
{
    public function __construct(
        private string $layer,
        private string $constant,
    ) {
    }

    public function appliesTo(ClassNode $classNode): bool
    {
        return $classNode->isInLayer($this->layer);
    }

    public function evaluate(ClassNode $classNode): ?RuleViolation
    {
        if (! $classNode->usesConstant($this->constant)) {
            return null;
        }

        return new RuleViolation(
            message:   sprintf(
                '%s [%s] must not use constant [%s]',
                $classNode->getType(),
                $classNode->className,
                $this->constant
            ),
            file:      $classNode->file,
            line:      $classNode->line,
            className: $classNode->className,
            layer:     $classNode->layer,
        );
    }
}
