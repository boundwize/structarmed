<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\PhpParser\Scalar;

use PhpParser\Node;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\NodeVisitorAbstract;

use function str_replace;

/**
 * Replaces a specifically identified plain decimal numeric spelling while
 * preserving the scalar's evaluated value and all surrounding source text.
 */
final class AddNumericLiteralSeparatorsVisitor extends NodeVisitorAbstract
{
    public function __construct(
        private readonly int $line,
        private readonly ?string $literal,
        private readonly ?string $replacement,
    ) {
    }

    public function enterNode(Node $node): ?Node
    {
        if ($this->shouldSkip($node)) {
            return null;
        }

        $attributes = $node->getAttributes();
        unset($attributes['origNode']);
        $attributes['rawValue']            = $this->replacement;
        $attributes['shouldPrintRawValue'] = true;

        return $node instanceof Int_
            ? new Int_($node->value, $attributes)
            : new Float_($node->value, $attributes);
    }

    /**
     * @phpstan-assert-if-false Int_|Float_ $node
     */
    private function shouldSkip(Node $node): bool
    {
        return $this->literal === null
            || $this->replacement === null
            || (! $node instanceof Int_ && ! $node instanceof Float_)
            || $node->getStartLine() !== $this->line
            || ($node instanceof Int_ && $node->getAttribute('kind') !== Int_::KIND_DEC)
            || $node->getAttribute('rawValue') !== $this->literal
            || str_replace('_', '', $this->replacement) !== $this->literal;
    }
}
