<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function preg_match;
use function sprintf;

final readonly class MustBeInterfaceRule implements RuleInterface
{
    public function __construct(
        private string $layer,
        private ?string $classNamePattern = null,
    ) {
    }

    public function appliesTo(ClassNode $classNode): bool
    {
        if (! $classNode->isInLayer($this->layer)) {
            return false;
        }

        if ($this->classNamePattern !== null) {
            return (bool) preg_match($this->classNamePattern, $classNode->className);
        }

        return true;
    }

    public function evaluate(ClassNode $classNode): ?RuleViolation
    {
        if ($classNode->isInterface) {
            return null;
        }

        return new RuleViolation(
            message:   sprintf(
                'Class [%s] must be an interface',
                $classNode->className
            ),
            file:      $classNode->file,
            line:      $classNode->line,
            className: $classNode->className,
            layer:     $classNode->layer,
        );
    }
}
