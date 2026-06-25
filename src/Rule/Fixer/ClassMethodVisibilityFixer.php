<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer;

use Boundwize\StructArmed\Rule\RuleViolation;

final readonly class ClassMethodVisibilityFixer
{
    public function fix(RuleViolation $ruleViolation): bool
    {
        if ($ruleViolation->line < 1 || $ruleViolation->methodName === null || $ruleViolation->methodName === '') {
            return false;
        }

        return (new PhpParserFixerProcessor())->process(
            $ruleViolation->file,
            new AddPublicMethodVisibilityVisitor(
                $ruleViolation->className,
                $ruleViolation->methodName
            ),
        );
    }
}
