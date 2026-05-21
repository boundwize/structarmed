<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function sprintf;

final readonly class ClassImplementingInterfaceMustHaveSuffixRule implements RuleInterface
{
    public function __construct(
        private string $layer,
        private string $interface,
        private string $suffix,
    ) {
    }

    public function appliesTo(ClassNode $classNode): bool
    {
        return $classNode->isClass()
            && $classNode->isInLayer($this->layer)
            && $classNode->implementsInterface($this->interface);
    }

    public function evaluate(ClassNode $classNode): ?RuleViolation
    {
        if ($classNode->nameEndsWith($this->suffix)) {
            return null;
        }

        return new RuleViolation(
            message:   sprintf(
                'Class [%s] implementing interface [%s] must have suffix [%s]',
                $classNode->className,
                $this->interface,
                $this->suffix
            ),
            file:      $classNode->file,
            line:      $classNode->line,
            className: $classNode->className,
            layer:     $classNode->layer,
        );
    }
}
