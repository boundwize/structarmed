<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\ExtendedClassAwareRuleInterface;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\AbstractPhpParserFixableRule;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\Class_\AddAbstractClassVisitor;
use Boundwize\StructArmed\Rule\RuleViolation;

use function sprintf;

final readonly class ExtendedClassMustBeAbstractOrReferencedRule extends AbstractPhpParserFixableRule implements
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
        if (! $classNode->isExtended) {
            return null;
        }

        // A dependency reference (instantiation, type hint, ::class, a
        // class-name string, ...) means the class is consumed as a value, so
        // making it abstract could break the referencing code.
        if ($classNode->isReferenced) {
            return null;
        }

        return new RuleViolation(
            message:   sprintf(
                'Extended class [%s] must be declared abstract or referenced as a dependency',
                $classNode->className
            ),
            file:      $classNode->file,
            line:      $classNode->line,
            className: $classNode->className,
            layer:     $classNode->layer,
        );
    }

    protected function createFixerVisitor(RuleViolation $ruleViolation): AddAbstractClassVisitor
    {
        return new AddAbstractClassVisitor($ruleViolation->className);
    }
}
