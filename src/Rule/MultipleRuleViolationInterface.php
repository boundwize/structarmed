<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule;

use Boundwize\StructArmed\Analyser\ClassNode;

interface MultipleRuleViolationInterface extends RuleInterface
{
    /**
     * @return list<RuleViolation>
     */
    public function evaluateAll(ClassNode $classNode): array;
}
