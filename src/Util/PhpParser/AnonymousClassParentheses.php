<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Util\PhpParser;

use PhpParser\Node\Stmt\Class_;
use PhpParser\Token;

use function end;

use const T_CLASS;
use const T_COMMENT;
use const T_DOC_COMMENT;
use const T_WHITESPACE;

/**
 * Locates the `()` an anonymous class may spell after the `class` keyword
 * while passing no constructor argument: `new class () {}`. PHP-Parser records
 * no such fact — both spellings parse to an empty argument list — so the token
 * stream the file was parsed into is the only place the parentheses exist.
 */
final class AnonymousClassParentheses
{
    /**
     * The index range of the empty parentheses the anonymous class carries,
     * including the whitespace separating them from what precedes; null when
     * it carries none, passes an argument, or has a comment inside them,
     * which removing the parentheses would delete.
     *
     * @param array<Token> $tokens
     * @return array{int, int}|null
     */
    public static function emptyTokenRange(array $tokens, Class_ $class): ?array
    {
        // An attribute argument may hold `Foo::class`, another T_CLASS token,
        // so the keyword is searched for after the last attribute group.
        $attrGroups = $class->attrGroups;
        $index      = $attrGroups === []
            ? $class->getStartTokenPos()
            : end($attrGroups)->getEndTokenPos() + 1;

        // Modifiers (`new readonly class`) still precede the keyword.
        while (isset($tokens[$index]) && $tokens[$index]->id !== T_CLASS) {
            $index++;
        }

        if (! isset($tokens[$index])) {
            return null;
        }

        $keyword = $index;
        $open    = self::skipWhitespaceAndComments($tokens, $keyword + 1);

        if (! isset($tokens[$open]) || $tokens[$open]->text !== '(') {
            return null;
        }

        $close = self::skipWhitespace($tokens, $open + 1);

        if (! isset($tokens[$close]) || $tokens[$close]->text !== ')') {
            return null;
        }

        // The whitespace before `(` goes too, back to the keyword or to a
        // comment in between, which stays.
        $first = $open;

        while ($first - 1 > $keyword && $tokens[$first - 1]->id === T_WHITESPACE) {
            $first--;
        }

        return [$first, $close];
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
            && ($tokens[$index]->id === T_WHITESPACE
                || $tokens[$index]->id === T_COMMENT
                || $tokens[$index]->id === T_DOC_COMMENT)
        ) {
            $index++;
        }

        return $index;
    }
}
