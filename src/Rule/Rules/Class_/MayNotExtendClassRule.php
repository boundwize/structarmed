<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function sprintf;

final readonly class MayNotExtendClassRule implements RuleInterface
{
    public function __construct(
        private string $layer,
        private string $class,
    ) {
    }

    public function appliesTo(ClassNode $classNode): bool
    {
        return $classNode->isClass() && $classNode->isInLayer($this->layer);
    }

    public function evaluate(ClassNode $classNode): ?RuleViolation
    {
        if (! $classNode->extendsClass($this->class)) {
            return null;
        }

        return new RuleViolation(
            message:   sprintf('Class [%s] must not extend class [%s]', $classNode->className, $this->class),
            file:      $classNode->file,
            line:      $classNode->line,
            className: $classNode->className,
            layer:     $classNode->layer,
        );
    }
}
