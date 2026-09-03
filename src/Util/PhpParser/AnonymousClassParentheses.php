<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Util\PhpParser;

use PhpParser\Node\Stmt\Class_;
use PhpParser\Token;

use const T_CLASS;
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
     * The index range, from the token after `class` through `)`, of the empty
     * parentheses the anonymous class carries; null when it carries none,
     * passes an argument, or has a comment inside the range.
     *
     * @param array<Token> $tokens
     * @return array{int, int}|null
     */
    public static function emptyTokenRange(array $tokens, Class_ $class): ?array
    {
        $index = $class->getStartTokenPos();

        // Attributes and modifiers (`new #[Attr] readonly class`) precede the keyword.
        while (isset($tokens[$index]) && $tokens[$index]->id !== T_CLASS) {
            $index++;
        }

        if (! isset($tokens[$index])) {
            return null;
        }

        $first = $index + 1;
        $index = self::skipWhitespace($tokens, $first);

        if (! isset($tokens[$index]) || $tokens[$index]->text !== '(') {
            return null;
        }

        $index = self::skipWhitespace($tokens, $index + 1);

        if (! isset($tokens[$index]) || $tokens[$index]->text !== ')') {
            return null;
        }

        return [$first, $index];
    }

    /** @param array<Token> $tokens */
    private static function skipWhitespace(array $tokens, int $index): int
    {
        while (isset($tokens[$index]) && $tokens[$index]->id === T_WHITESPACE) {
            $index++;
        }

        return $index;
    }
}
