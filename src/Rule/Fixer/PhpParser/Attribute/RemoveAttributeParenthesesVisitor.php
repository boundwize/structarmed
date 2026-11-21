<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\PhpParser\Attribute;

use Boundwize\StructArmed\Rule\Fixer\PhpParser\AbstractTokenAwareVisitor;
use Boundwize\StructArmed\Util\PhpParser\EmptyParentheses;
use PhpParser\Node;
use PhpParser\Node\Attribute;

/**
 * Removes the empty `()` from the attributes starting on the given line by
 * blanking those tokens; no node is re-printed, so the declaration carrying
 * the attribute keeps its formatting.
 *
 * Several attributes may start on one line, and that line is the identity a
 * violation carries. Re-applying the rule's own condition here — instead of
 * trusting the line alone — means every attribute this visitor changes is one
 * the rule flags.
 */
final class RemoveAttributeParenthesesVisitor extends AbstractTokenAwareVisitor
{
    public function __construct(
        private readonly int $line,
    ) {
    }

    public function enterNode(Node $node): ?Node
    {
        if (! $node instanceof Attribute || $node->getStartLine() !== $this->line) {
            return null;
        }

        $range = EmptyParentheses::tokenRangeAfter($this->tokens, $node->name->getEndTokenPos());

        if ($range === null) {
            return null;
        }

        // A `,` or the closing `]` follows the parentheses, so nothing has
        // to take their place; an already-fixed attribute yields no range.
        [$first, $last] = $range;

        for ($index = $first; $index <= $last; $index++) {
            $this->tokens[$index]->text = '';
        }

        return $node;
    }
}
