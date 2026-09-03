<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\PhpParser;

use Boundwize\StructArmed\Rule\FixableInterface;
use Boundwize\StructArmed\Rule\RuleViolation;
use PhpParser\NodeVisitor;

abstract readonly class AbstractPhpParserFixableRule implements FixableInterface
{
    /**
     * Additional violations let the CLI fix one rule's violations for a file
     * in a single read, parse, and write cycle.
     */
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
            $this->shouldRemoveFileWhenEmpty(),
        );
    }

    abstract protected function createFixerVisitor(RuleViolation $ruleViolation): NodeVisitor;

    /**
     * Whether the fixed file should be deleted when the fix leaves no code
     * behind — only declare/namespace/use boilerplate. Rules whose fix removes
     * whole declarations opt in by returning true.
     */
    protected function shouldRemoveFileWhenEmpty(): bool
    {
        return false;
    }

    private function fixerProcessor(): PhpParserFixerProcessor
    {
        static $processor;

        return $processor instanceof PhpParserFixerProcessor
            ? $processor
            : ($processor = new PhpParserFixerProcessor());
    }
}
