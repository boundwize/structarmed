<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Usage;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function preg_match;
use function sprintf;

final readonly class MayNotUseClassRule implements RuleInterface
{
    public function __construct(
        private string $layer,
        private string $forbiddenClass,
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
        if (! $classNode->dependsOn($this->forbiddenClass)) {
            return null;
        }

        return new RuleViolation(
            message:   sprintf(
                'Class [%s] must not use [%s]',
                $classNode->className,
                $this->forbiddenClass
            ),
            file:      $classNode->file,
            line:      $classNode->line,
            className: $classNode->className,
            layer:     $classNode->layer,
        );
    }
}
