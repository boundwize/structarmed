<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\ExtendedClassAwareRuleInterface;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\AbstractPhpParserFixableRule;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\Class_\AddFinalClassVisitor;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function sprintf;

final readonly class MustBeFinalRule extends AbstractPhpParserFixableRule implements
    RuleInterface,
    ExtendedClassAwareRuleInterface
{
    public function __construct(
        private string $layer,
        private ?string $classNamePattern = null,
    ) {
    }

    public function appliesTo(ClassNode $classNode): bool
    {
        if (! $classNode->isClass() || $classNode->isAbstract) {
            return false;
        }

        if (! $classNode->isInLayer($this->layer)) {
            return false;
        }

        if ($this->classNamePattern !== null) {
            return $classNode->nameMatches($this->classNamePattern, isFullName: true);
        }

        return true;
    }

    public function evaluate(ClassNode $classNode): ?RuleViolation
    {
        if ($classNode->isFinal) {
            return null;
        }

        // A class another scanned class extends cannot be made final; forcing it
        // would break the child, so treat it as legitimately non-final.
        if ($classNode->isExtended) {
            return null;
        }

        return new RuleViolation(
            message:   sprintf(
                'Class [%s] must be declared final',
                $classNode->className
            ),
            file:      $classNode->file,
            line:      $classNode->line,
            className: $classNode->className,
            layer:     $classNode->layer,
        );
    }

    protected function createFixerVisitor(RuleViolation $ruleViolation): AddFinalClassVisitor
    {
        return new AddFinalClassVisitor($ruleViolation->className);
    }
}
