<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Util\PhpParser;

use PhpParser\Node\Stmt\Class_;
use PhpParser\Token;

use function end;

use const T_CLASS;

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
     * see {@see EmptyParentheses::tokenRangeAfter()}; null when it carries
     * none, passes an argument, or has a comment inside them.
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

        return EmptyParentheses::tokenRangeAfter($tokens, $index);
    }
}
