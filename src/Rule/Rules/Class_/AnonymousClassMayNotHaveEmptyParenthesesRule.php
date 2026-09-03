<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Class_;

use Boundwize\StructArmed\Analyser\AnonymousClassNode;
use Boundwize\StructArmed\Rule\AnonymousClassRuleInterface;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\AbstractPhpParserFixableRule;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\Class_\RemoveAnonymousClassParenthesesVisitor;
use Boundwize\StructArmed\Rule\RuleViolation;

use function sprintf;

/**
 * PER Coding Style: an anonymous class that passes no constructor argument
 * omits the parentheses after `class` — `new class {}`, not `new class () {}`.
 *
 * @see https://www.php-fig.org/per/coding-style/#8-anonymous-classes
 */
final readonly class AnonymousClassMayNotHaveEmptyParenthesesRule extends AbstractPhpParserFixableRule implements
    AnonymousClassRuleInterface
{
    public function __construct(
        private string $layer,
    ) {
    }

    public function appliesTo(AnonymousClassNode $anonymousClassNode): bool
    {
        return $anonymousClassNode->isInLayer($this->layer);
    }

    public function evaluate(AnonymousClassNode $anonymousClassNode): ?RuleViolation
    {
        if (! $anonymousClassNode->hasEmptyParentheses) {
            return null;
        }

        return new RuleViolation(
            message:   sprintf(
                'Anonymous class in [%s] may not have empty parentheses after `class`',
                $anonymousClassNode->enclosingScopeName()
            ),
            file:      $anonymousClassNode->file,
            line:      $anonymousClassNode->line,
            className: $anonymousClassNode->enclosingScopeName(),
            layer:     $anonymousClassNode->layer,
        );
    }

    protected function createFixerVisitor(RuleViolation $ruleViolation): RemoveAnonymousClassParenthesesVisitor
    {
        return new RemoveAnonymousClassParenthesesVisitor($ruleViolation->line);
    }
}
