<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\JsonRecast;

use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitor;
use Boundwize\StructArmed\Rule\FixableInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

abstract readonly class AbstractJsonRecastFixableRule implements FixableInterface
{
    final public function fix(RuleViolation $ruleViolation, RuleViolation ...$additionalViolations): bool
    {
        $ruleViolations = [$ruleViolation, ...$additionalViolations];
        $file           = $ruleViolation->file;
        $nodeVisitors   = [];

        foreach ($ruleViolations as $ruleViolation) {
            if ($ruleViolation->file !== $file) {
                return false;
            }

            $nodeVisitors[] = $this->createFixerVisitor($ruleViolation);
        }

        return $this->fixerProcessor()->process(
            $file,
            $nodeVisitors,
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
