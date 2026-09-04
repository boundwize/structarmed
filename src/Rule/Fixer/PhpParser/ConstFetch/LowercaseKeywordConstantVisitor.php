<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\PhpParser\ConstFetch;

use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Name\Relative;
use PhpParser\NodeVisitorAbstract;

use function strtolower;

/**
 * Lowercases every `true`, `false`, or `null` fetch on a line that is not yet
 * spelled in lowercase. The Name is replaced by one of the same kind, so a
 * fully qualified `\TRUE` keeps its leading `\` and becomes `\true`. A
 * relative `namespace\TRUE` names the case-sensitive constant `Ns\TRUE`, not
 * the keyword, so it is left alone.
 */
final class LowercaseKeywordConstantVisitor extends NodeVisitorAbstract
{
    private const KEYWORD_CONSTANTS = [
        'true'  => true,
        'false' => true,
        'null'  => true,
    ];

    /**
     * @param string|null $spelling The spelling as written without a leading `\`, e.g. `TRUE`.
     *                              A violation without it does not identify an occurrence, so
     *                              the visitor then changes nothing rather than widening the fix.
     */
    public function __construct(
        private readonly int $line,
        private readonly ?string $spelling,
    ) {
    }

    public function enterNode(Node $node): ?Node
    {
        if (
            $this->spelling === null
            || ! $node instanceof ConstFetch
            || $node->getStartLine() !== $this->line
            || $node->name instanceof Relative
        ) {
            return null;
        }

        $name     = $node->name;
        $spelling = $name->toString();
        $keyword  = strtolower($spelling);

        if (! isset(self::KEYWORD_CONSTANTS[$keyword]) || $spelling === $keyword || $spelling !== $this->spelling) {
            return null;
        }

        $node->name = $name instanceof FullyQualified
            ? new FullyQualified($keyword, $name->getAttributes())
            : new Name($keyword, $name->getAttributes());

        return $node;
    }
}
