<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Function_;

use Boundwize\StructArmed\Analyser\FunctionNode;
use Boundwize\StructArmed\Rule\FunctionRuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function sprintf;

final readonly class MustHaveReturnTypeFunctionRule implements FunctionRuleInterface
{
    public function __construct(
        private string $layer,
    ) {
    }

    public function appliesToFunction(FunctionNode $functionNode): bool
    {
        return $functionNode->isInLayer($this->layer);
    }

    public function evaluateFunction(FunctionNode $functionNode): ?RuleViolation
    {
        if ($functionNode->hasReturnType) {
            return null;
        }

        return new RuleViolation(
            message:      sprintf(
                'Function [%s()] must declare a return type',
                $functionNode->functionName
            ),
            file:         $functionNode->file,
            line:         $functionNode->line,
            className:    $functionNode->functionName,
            layer:        $functionNode->layer,
            functionName: $functionNode->functionName,
        );
    }
}
