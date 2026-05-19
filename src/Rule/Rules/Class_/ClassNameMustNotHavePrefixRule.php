<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function sprintf;

final readonly class ClassNameMustNotHavePrefixRule implements RuleInterface
{
    public function __construct(
        private string $layer,
        private string $prefix,
    ) {
    }

    public function appliesTo(ClassNode $classNode): bool
    {
        return $classNode->isInLayer($this->layer);
    }

    public function evaluate(ClassNode $classNode): ?RuleViolation
    {
        if (! $classNode->nameStartsWith($this->prefix)) {
            return null;
        }

        return new RuleViolation(
            message:   sprintf(
                'Class [%s] must not have prefix [%s]',
                $classNode->className,
                $this->prefix
            ),
            file:      $classNode->file,
            line:      $classNode->line,
            className: $classNode->className,
            layer:     $classNode->layer,
        );
    }
}
