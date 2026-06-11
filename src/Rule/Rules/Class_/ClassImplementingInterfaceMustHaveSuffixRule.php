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
        if (! $classNode->isInLayer($this->layer)) {
            return false;
        }

        if ($classNode->isClass()) {
            return $classNode->implementsInterface($this->interface);
        }

        return $classNode->isInterface
            && $classNode->extendsInterface($this->interface);
    }

    public function evaluate(ClassNode $classNode): ?RuleViolation
    {
        if ($classNode->nameEndsWith($this->suffix)) {
            return null;
        }

        $declaration = $classNode->isInterface ? 'Interface' : 'Class';
        $relation    = $classNode->isInterface ? 'extending' : 'implementing';

        return new RuleViolation(
            message:   sprintf(
                '%s [%s] %s interface [%s] must have suffix [%s]',
                $declaration,
                $classNode->className,
                $relation,
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
