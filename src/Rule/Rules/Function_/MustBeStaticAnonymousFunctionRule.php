<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Function_;

use Boundwize\StructArmed\Analyser\AnonymousFunctionNode;
use Boundwize\StructArmed\Rule\AnonymousFunctionRuleInterface;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\AbstractPhpParserFixableRule;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\FunctionLike\AddStaticAnonymousFunctionVisitor;
use Boundwize\StructArmed\Rule\RuleViolation;

use function sprintf;

/**
 * Closures and arrow functions that do not read `$this` are declared
 * `static`, so they never capture the enclosing object.
 */
final readonly class MustBeStaticAnonymousFunctionRule extends AbstractPhpParserFixableRule implements
    AnonymousFunctionRuleInterface
{
    public function __construct(
        private string $layer,
    ) {
    }

    public function appliesToAnonymousFunction(AnonymousFunctionNode $anonymousFunctionNode): bool
    {
        return $anonymousFunctionNode->isInLayer($this->layer);
    }

    public function evaluateAnonymousFunction(AnonymousFunctionNode $anonymousFunctionNode): ?RuleViolation
    {
        // A closure reading `$this` cannot be static: PHP raises an error
        // when a static closure accesses `$this`.
        if ($anonymousFunctionNode->isStatic || $anonymousFunctionNode->usesThis) {
            return null;
        }

        return new RuleViolation(
            message:   sprintf(
                '%s in [%s] must be declared static',
                $anonymousFunctionNode->getType(),
                $anonymousFunctionNode->enclosingScopeName()
            ),
            file:      $anonymousFunctionNode->file,
            line:      $anonymousFunctionNode->line,
            className: $anonymousFunctionNode->enclosingScopeName(),
            layer:     $anonymousFunctionNode->layer,
        );
    }

    protected function createFixerVisitor(RuleViolation $ruleViolation): AddStaticAnonymousFunctionVisitor
    {
        return new AddStaticAnonymousFunctionVisitor($ruleViolation->line);
    }
}
