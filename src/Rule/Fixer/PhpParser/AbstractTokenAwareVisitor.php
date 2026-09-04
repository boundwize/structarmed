<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\PhpParser;

use PhpParser\NodeVisitorAbstract;
use PhpParser\Token;

/**
 * A fixer visitor that edits the token stream the file was parsed into, for
 * fixes the AST cannot express: punctuation and whitespace PHP-Parser records
 * in no node, such as the `()` of `new class () {}`. The format-preserving
 * printer assembles every unchanged node from these tokens, so an edited
 * token text reaches the fixed file without any node being re-printed.
 */
abstract class AbstractTokenAwareVisitor extends NodeVisitorAbstract
{
    /** @var array<Token> The mutable tokens the file being fixed was parsed into */
    protected array $tokens = [];

    /** @param array<Token> $tokens */
    public function setTokens(array $tokens): void
    {
        $this->tokens = $tokens;
    }
}
