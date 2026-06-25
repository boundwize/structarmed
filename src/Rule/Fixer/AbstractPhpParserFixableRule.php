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
        if ($nodeVisitor === null) {
            return false;
        }

        return self::fixerProcessor()->process(
            $ruleViolation->file,
            $nodeVisitor,
        );
    }

    protected function createFixerVisitor(RuleViolation $ruleViolation): ?NodeVisitor
    {
        return null;
    }

    private static function fixerProcessor(): PhpParserFixerProcessor
    {
        static $processor;

        return $processor instanceof PhpParserFixerProcessor ? $processor : $processor = new PhpParserFixerProcessor();
    }
}
