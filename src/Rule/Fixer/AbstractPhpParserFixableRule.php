<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer;

use Boundwize\StructArmed\Rule\FixableInterface;
use Boundwize\StructArmed\Rule\RuleViolation;
use PhpParser\NodeVisitor;

abstract readonly class AbstractPhpParserFixableRule implements FixableInterface
{
    final public function fix(RuleViolation $ruleViolation): bool
    {
        $nodeVisitor = $this->createFixerVisitor($ruleViolation);

        return $this->fixerProcessor()->process(
            $ruleViolation->file,
            $nodeVisitor,
        );
    }

    abstract protected function createFixerVisitor(RuleViolation $ruleViolation): NodeVisitor;

    private function fixerProcessor(): PhpParserFixerProcessor
    {
        static $processor;

        return $processor instanceof PhpParserFixerProcessor
            ? $processor
            : ($processor = new PhpParserFixerProcessor());
    }
}
