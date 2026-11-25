<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\PhpParser;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Nop;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

use function file_get_contents;
use function file_put_contents;
use function is_file;
use function is_string;
use function substr_count;
use function unlink;

final class PhpParserFixerProcessor
{
    /**
     * Whether the last successful process() call changed the number of lines
     * of its file, deleting it included. Only meaningful right after a call that
     * returned true: every call resets it first.
     */
    public static bool $lastFixChangedLineCount = false;

    /** @param NodeVisitor|non-empty-list<NodeVisitor> $nodeVisitors */
    public function process(string $file, NodeVisitor|array $nodeVisitors, bool $removeFileWhenEmpty = false): bool
    {
        self::$lastFixChangedLineCount = false;

        if (! is_file($file)) {
            return false;
        }

        if ($nodeVisitors instanceof NodeVisitor) {
            $nodeVisitors = [$nodeVisitors];
        }

        $code = (string) file_get_contents($file);

        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        try {
            $originalStatements = $parser->parse($code);
        } catch (Error) {
            return false;
        }

        if ($originalStatements === null || $originalStatements === []) {
            return false;
        }

        $tokens     = $parser->getTokens();
        $statements = (new NodeTraverser(new CloningVisitor()))->traverse($originalStatements);
        $statements = (new NodeTraverser(new NameResolver(options: ['replaceNodes' => false])))
            ->traverse($statements);

        foreach ($nodeVisitors as $nodeVisitor) {
            // A token edit lands in the output through the same tokens the
            // format-preserving printer copies unchanged code from.
            if ($nodeVisitor instanceof AbstractTokenAwareVisitor) {
                $nodeVisitor->setTokens($tokens);
            }

            $statements = (new NodeTraverser($nodeVisitor))->traverse($statements);
        }

        // A fix that removes the last declaration leaves only boilerplate
        // (declare/namespace/use); the whole file is dead weight at that point.
        if ($removeFileWhenEmpty && $this->hasOnlyDeclarations($statements)) {
            return self::$lastFixChangedLineCount = unlink($file);
        }

        $prettyPrinter = new class extends Standard {
            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- PHP-Parser extension hook.
            protected function pScalar_Float(Float_ $node): string
            {
                $rawValue = $node->getAttribute('rawValue');

                if ($node->getAttribute('shouldPrintRawValue') === true && is_string($rawValue)) {
                    return $rawValue;
                }

                return parent::pScalar_Float($node);
            }

            /**
             * A namespace emptied by a fix is printed anew, and the printer
             * picks the `namespace N;` form unless told otherwise; next to
             * another bracketed namespace that is a compile error, so one
             * written with braces keeps them.
             */
            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- PHP-Parser extension hook.
            protected function pStmt_Namespace(Namespace_ $node): string
            {
                $this->canUseSemicolonNamespaces = $node->getAttribute('kind') !== Namespace_::KIND_BRACED;

                return parent::pStmt_Namespace($node);
            }
        };
        $fixedCode     = $prettyPrinter->printFormatPreserving($statements, $originalStatements, $tokens);

        if ($fixedCode === $code || file_put_contents($file, $fixedCode) === false) {
            return false;
        }

        // Both versions are already in memory, so no extra read is needed.
        self::$lastFixChangedLineCount = substr_count($code, "\n") !== substr_count($fixedCode, "\n");

        return true;
    }

    /**
     * @param Node[] $statements
     */
    private function hasOnlyDeclarations(array $statements): bool
    {
        foreach ($statements as $statement) {
            // The block form `declare(...) { ... }` carries statements of its
            // own, so only an empty-bodied declare counts as boilerplate.
            if ($statement instanceof Declare_) {
                if ($statement->stmts !== null && ! $this->hasOnlyDeclarations($statement->stmts)) {
                    return false;
                }

                continue;
            }

            if (
                $statement instanceof Use_
                || $statement instanceof GroupUse
                || $statement instanceof Nop
            ) {
                continue;
            }

            if ($statement instanceof Namespace_) {
                if (! $this->hasOnlyDeclarations($statement->stmts)) {
                    return false;
                }

                continue;
            }

            return false;
        }

        return true;
    }
}
