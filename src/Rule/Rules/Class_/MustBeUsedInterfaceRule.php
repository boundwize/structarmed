<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\AbstractPhpParserFixableRule;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\ClassLike\RemoveClassLikeVisitor;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Rule\UsedInterfaceAwareRuleInterface;

use function sprintf;

final readonly class MustBeUsedInterfaceRule extends AbstractPhpParserFixableRule implements
    UsedInterfaceAwareRuleInterface
{
    public function __construct(
        private string $layer,
        private ?string $classNamePattern = null,
    ) {
    }

    public function appliesTo(ClassNode $classNode): bool
    {
        if (! $classNode->isInterface) {
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
        if ($classNode->isImplemented) {
            return null;
        }

        // A dependency reference (instanceof, type hint, ::class, ...) means
        // removing the interface would break the referencing code.
        if ($classNode->isReferenced) {
            return null;
        }

        return new RuleViolation(
            message:   sprintf(
                'Interface [%s] must be implemented by a class, extended by another interface,'
                    . ' or referenced as a dependency',
                $classNode->className
            ),
            file:      $classNode->file,
            line:      $classNode->line,
            className: $classNode->className,
            layer:     $classNode->layer,
        );
    }

    protected function createFixerVisitor(RuleViolation $ruleViolation): RemoveClassLikeVisitor
    {
        return new RemoveClassLikeVisitor($ruleViolation->className);
    }

    protected function shouldRemoveFileWhenEmpty(): bool
    {
        return true;
    }
}
