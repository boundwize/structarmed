<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Function_;

use Boundwize\StructArmed\Analyser\FunctionNode;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\AbstractPhpParserFixableRule;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\Function_\RemoveFunctionVisitor;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Rule\UsedFunctionAwareRuleInterface;

use function sprintf;

final readonly class MustBeUsedFunctionRule extends AbstractPhpParserFixableRule implements
    UsedFunctionAwareRuleInterface
{
    public function __construct(
        private string $layer,
        private ?string $functionNamePattern = null,
    ) {
    }

    public function appliesTo(FunctionNode $functionNode): bool
    {
        if (! $functionNode->isInLayer($this->layer)) {
            return false;
        }

        if ($this->functionNamePattern !== null) {
            return $functionNode->nameMatches($this->functionNamePattern, isFullName: true);
        }

        return true;
    }

    public function evaluate(FunctionNode $functionNode): ?RuleViolation
    {
        if ($functionNode->isReferenced) {
            return null;
        }

        return new RuleViolation(
            message:      sprintf(
                'Function [%s()] must be called or referenced as a callable',
                $functionNode->functionName
            ),
            file:         $functionNode->file,
            line:         $functionNode->line,
            className:    $functionNode->functionName,
            layer:        $functionNode->layer,
            functionName: $functionNode->functionName,
        );
    }

    protected function createFixerVisitor(RuleViolation $ruleViolation): RemoveFunctionVisitor
    {
        return new RemoveFunctionVisitor($ruleViolation->className);
    }

    protected function shouldRemoveFileWhenEmpty(): bool
    {
        return true;
    }
}
