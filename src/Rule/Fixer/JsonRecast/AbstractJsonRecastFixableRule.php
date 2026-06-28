<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\JsonRecast;

use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitor;
use Boundwize\StructArmed\Rule\FixableInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

abstract readonly class AbstractJsonRecastFixableRule implements FixableInterface
{
    final public function fix(RuleViolation $ruleViolation): bool
    {
        return $this->fixerProcessor()->process(
            $ruleViolation->file,
            $this->createFixerVisitor($ruleViolation),
        );
    }

    abstract protected function createFixerVisitor(RuleViolation $ruleViolation): NodeJsonVisitor;

    private function fixerProcessor(): JsonRecastFixerProcessor
    {
        static $processor;

        return $processor instanceof JsonRecastFixerProcessor
            ? $processor
            : ($processor = new JsonRecastFixerProcessor());
    }
}
