<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Util\PhpParser;

use PhpParser\Token;

use function in_array;
use function str_starts_with;

use const T_COMMENT;
use const T_DOC_COMMENT;
use const T_WHITESPACE;

/**
 * Locates an empty `()` written after a token: the `class` keyword of
 * `new class () {}`, or the attribute name of `#[Foo()]`. PHP-Parser records
 * no such fact — with or without them the argument list parses as empty — so
 * the token stream the file was parsed into is the only place the parentheses
 * exist.
 */
final class EmptyParentheses
{
    /**
     * The index range of the empty parentheses following the token at $index,
     * including the whitespace separating them from what precedes; null when
     * none follow, they hold an argument, or they hold a comment, which
     * removing the parentheses would delete.
     *
     * @param array<Token> $tokens
     * @return array{int, int}|null
     */
    public static function tokenRangeAfter(array $tokens, int $index): ?array
    {
        $open = self::skipWhitespaceAndComments($tokens, $index + 1);

        if (! isset($tokens[$open]) || $tokens[$open]->text !== '(') {
            return null;
        }

        $close = self::skipWhitespace($tokens, $open + 1);

        if (! isset($tokens[$close]) || $tokens[$close]->text !== ')') {
            return null;
        }

        // The whitespace before `(` goes too, back to the token or to a
        // comment in between, which stays.
        $first = $open;

        while ($first - 1 > $index && $tokens[$first - 1]->id === T_WHITESPACE) {
            $first--;
        }

        // A `#` or `//` comment ends at the newline that whitespace holds, so
        // removing it would pull what follows the parentheses into the comment.
        if (self::isLineComment($tokens[$first - 1])) {
            $first = $open;
        }

        return [$first, $close];
    }

    private static function isLineComment(Token $token): bool
    {
        return $token->id === T_COMMENT && ! str_starts_with($token->text, '/*');
    }

    /** @param array<Token> $tokens */
    private static function skipWhitespace(array $tokens, int $index): int
    {
        while (isset($tokens[$index]) && $tokens[$index]->id === T_WHITESPACE) {
            $index++;
        }

        return $index;
    }

    /** @param array<Token> $tokens */
    private static function skipWhitespaceAndComments(array $tokens, int $index): int
    {
        while (
            isset($tokens[$index])
            && (in_array($tokens[$index]->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))
        ) {
            $index++;
        }

        return $index;
    }
}
