<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\CollectorState;

use PhpParser\Token;

/**
 * Source context and spelling facts for one file. Retained after traversal so
 * the extractor can read the facts, then replaced when starting the next file.
 *
 * @internal
 */
final class FileSyntaxAnalysis
{
    /**
     * Keyword constants not spelled in canonical lowercase, as [line, spelling
     * as written]; a leading backslash marks a fully qualified form.
     *
     * @var list<array{int, string}>
     */
    public array $nonCanonicalKeywordConstants = [];

    /** @var list<array{int, string, int|float}> Numeric literals as [line, spelling, evaluated value] */
    public array $numericLiterals = [];

    /** @var list<array{int, string}> Empty attribute parentheses as [line, resolved attribute name] */
    public array $attributesWithEmptyParentheses = [];

    /** @param array<Token> $tokens The source tokens for facts not carried by the AST */
    public function __construct(
        public readonly string $file = '',
        public readonly array $tokens = [],
    ) {
    }
}
