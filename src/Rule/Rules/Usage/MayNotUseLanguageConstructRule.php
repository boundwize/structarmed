<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Usage;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function sprintf;

final readonly class MayNotUseLanguageConstructRule implements RuleInterface
{
    public function __construct(
        private string $layer,
        private string $construct,
    ) {
    }

    public function appliesTo(ClassNode $classNode): bool
    {
        return $classNode->isInLayer($this->layer);
    }

    public function evaluate(ClassNode $classNode): ?RuleViolation
    {
        if (! $classNode->usesLanguageConstruct($this->construct)) {
            return null;
        }

        return new RuleViolation(
            message:   sprintf(
                'Class [%s] must not use language construct [%s]',
                $classNode->className,
                $this->construct
            ),
            file:      $classNode->file,
            line:      $classNode->line,
            className: $classNode->className,
            layer:     $classNode->layer,
        );
    }
}
