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
 * fully qualified `\TRUE` keeps its leading `\` and becomes `\true`.
 */
final class LowercaseKeywordConstantVisitor extends NodeVisitorAbstract
{
    private const KEYWORD_CONSTANTS = [
        'true'  => true,
        'false' => true,
        'null'  => true,
    ];

    /**
     * @param string|null $spelling The spelling as written without a leading `\`, e.g. `TRUE`;
     *                              null lowercases every keyword constant on the line.
     */
    public function __construct(
        private readonly int $line,
        private readonly ?string $spelling = null,
    ) {
    }

    public function enterNode(Node $node): ?Node
    {
        if (! $node instanceof ConstFetch || $node->getStartLine() !== $this->line) {
            return null;
        }

        $name     = $node->name;
        $spelling = $name->toString();
        $keyword  = strtolower($spelling);

        if (
            ! isset(self::KEYWORD_CONSTANTS[$keyword])
            || $spelling === $keyword
            || ($this->spelling !== null && $spelling !== $this->spelling)
        ) {
            return null;
        }

        $node->name = match (true) {
            $name instanceof FullyQualified => new FullyQualified($keyword, $name->getAttributes()),
            $name instanceof Relative       => new Relative($keyword, $name->getAttributes()),
            default                         => new Name($keyword, $name->getAttributes()),
        };

        return $node;
    }
}
