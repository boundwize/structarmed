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

        foreach ($additionalViolations as $additionalViolation) {
            if ($additionalViolation->file !== $file) {
                return false;
            }
        }

        return $this->fixerProcessor()->process(
            $file,
            $this->createFixerVisitors($ruleViolations),
            $this->shouldRemoveFileWhenEmpty(),
        );
    }

    abstract protected function createFixerVisitor(RuleViolation $ruleViolation): NodeVisitor;

    /**
     * One visitor per violation, each traversing the file once. A rule whose
     * visitor can fix every violation of the file in one traversal overrides
     * this.
     *
     * @param non-empty-array<RuleViolation> $ruleViolations All in one file
     * @return non-empty-list<NodeVisitor>
     */
    protected function createFixerVisitors(array $ruleViolations): array
    {
        $nodeVisitors = [];

        foreach ($ruleViolations as $ruleViolation) {
            $nodeVisitors[] = $this->createFixerVisitor($ruleViolation);
        }

        return $nodeVisitors;
    }

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
