<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Util\PhpParser;

use PhpParser\Node\Stmt;
use PhpParser\Token;

use function in_array;
use function str_contains;

use const T_COMMENT;
use const T_DOC_COMMENT;
use const T_WHITESPACE;

/**
 * Locates the code an enum member shares its line with: PHP-Parser records a
 * statement's line numbers, but not where the previous statement or the enum
 * brace ends, so the token stream is the only place that boundary exists.
 *
 * A gap is the run of whitespace and comments between a member and the code
 * next to it, identified by the indexes of the two code tokens around it. It
 * counts as shared when its text holds no newline, so a fixer that inserts a
 * line break into a gap finds the same gap already separated afterwards.
 */
final class EnumCaseLine
{
    /** @param array<Token> $tokens */
    public static function sharesLine(array $tokens, Stmt $stmt): bool
    {
        return self::precedingGap($tokens, $stmt) !== null
            || self::followingGap($tokens, $stmt) !== null;
    }

    /**
     * The code token before the statement (the end of the previous member,
     * or the enum's `{`) and the statement's first token, when no newline
     * separates them.
     *
     * @param array<Token> $tokens
     * @return array{int, int}|null
     */
    public static function precedingGap(array $tokens, Stmt $stmt): ?array
    {
        $start = $stmt->getStartTokenPos();

        if (! isset($tokens[$start])) {
            return null;
        }

        $previous = $start - 1;

        while (isset($tokens[$previous]) && self::isTrivia($tokens[$previous])) {
            $previous--;
        }

        if (! isset($tokens[$previous]) || self::hasNewline($tokens, $previous, $start)) {
            return null;
        }

        return [$previous, $start];
    }

    /**
     * The statement's last token and the code token after it (the start of
     * the next member, or the enum's `}`), when no newline separates them.
     *
     * @param array<Token> $tokens
     * @return array{int, int}|null
     */
    public static function followingGap(array $tokens, Stmt $stmt): ?array
    {
        $end = $stmt->getEndTokenPos();

        if (! isset($tokens[$end])) {
            return null;
        }

        $next = $end + 1;

        while (isset($tokens[$next]) && self::isTrivia($tokens[$next])) {
            $next++;
        }

        if (! isset($tokens[$next]) || self::hasNewline($tokens, $end, $next)) {
            return null;
        }

        return [$end, $next];
    }

    public static function isTrivia(Token $token): bool
    {
        return in_array($token->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    /**
     * Whether the tokens from $from up to (not including) $to hold a newline.
     * $from is a code token, included because a fixer may append the line
     * break to it when the gap holds no token to carry it.
     *
     * @param array<Token> $tokens
     */
    private static function hasNewline(array $tokens, int $from, int $to): bool
    {
        for ($index = $from; $index < $to; $index++) {
            if (str_contains($tokens[$index]->text, "\n")) {
                return true;
            }
        }

        return false;
    }
}
