<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\PhpParser\Class_;

use Boundwize\StructArmed\Rule\Fixer\PhpParser\TokenAwareVisitorInterface;
use Boundwize\StructArmed\Util\PhpParser\AnonymousClassParentheses;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Token;

use const T_WHITESPACE;

/**
 * Removes the empty `()` from the anonymous classes starting on the given
 * line by blanking those tokens; the class itself is not re-printed, so its
 * body keeps its formatting.
 *
 * An anonymous class has no name, so its start line is the only identity a
 * violation can carry, and several may start on one line. Re-applying the
 * rule's own condition here — instead of trusting the line alone — means
 * every class this visitor changes is one the rule flags.
 */
final class RemoveAnonymousClassParenthesesVisitor extends NodeVisitorAbstract implements TokenAwareVisitorInterface
{
    /** @var array<Token> */
    private array $tokens = [];

    public function __construct(
        private readonly int $line,
    ) {
    }

    public function setTokens(array $tokens): void
    {
        $this->tokens = $tokens;
    }

    public function enterNode(Node $node): ?Node
    {
        if (! $node instanceof Class_ || $node->name instanceof Identifier || $node->getStartLine() !== $this->line) {
            return null;
        }

        $range = AnonymousClassParentheses::emptyTokenRange($this->tokens, $node);

        if ($range === null) {
            return null;
        }

        // A range always holds the `(` and `)` tokens with their text, so
        // blanking it is always a change; an already-fixed class yields no range.
        [$first, $last] = $range;

        for ($index = $first; $index <= $last; $index++) {
            $this->tokens[$index]->text = '';
        }

        // `new class(){}` keeps a space between the keyword and what follows.
        if (! isset($this->tokens[$last + 1]) || $this->tokens[$last + 1]->id !== T_WHITESPACE) {
            $this->tokens[$last]->text = ' ';
        }

        return $node;
    }
}
