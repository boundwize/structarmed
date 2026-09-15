<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Util\PhpParser;

use PhpParser\Node;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;

/**
 * The functions a file declares by merely being loaded: direct children of
 * the file, its namespaces and declare blocks. A function nested in another
 * function, a conditional or a loop only exists once that code has run, so
 * those blocks are not descended into.
 */
final class UnconditionallyDeclaredFunctions
{
    /**
     * Lower-cased namespaced names: PHP function names are case-insensitive.
     *
     * @param array<Node> $nodes
     * @return array<string, true>
     */
    public static function names(array $nodes): array
    {
        $functions = [];

        foreach ($nodes as $node) {
            if (($node instanceof Namespace_ || $node instanceof Declare_) && $node->stmts !== null) {
                $functions += self::names($node->stmts);

                continue;
            }

            if ($node instanceof Function_) {
                $functions[($node->namespacedName ?? $node->name)->toLowerString()] = true;
            }
        }

        return $functions;
    }
}
