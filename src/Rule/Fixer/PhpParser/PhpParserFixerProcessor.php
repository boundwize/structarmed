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
use function unlink;

final readonly class PhpParserFixerProcessor
{
    /** @param NodeVisitor|non-empty-list<NodeVisitor> $nodeVisitors */
    public function process(string $file, NodeVisitor|array $nodeVisitors, bool $removeFileWhenEmpty = false): bool
    {
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
            if ($nodeVisitor instanceof TokenAwareVisitorInterface) {
                $nodeVisitor->setTokens($tokens);
            }

            $statements = (new NodeTraverser($nodeVisitor))->traverse($statements);
        }

        // A fix that removes the last declaration leaves only boilerplate
        // (declare/namespace/use); the whole file is dead weight at that point.
        if ($removeFileWhenEmpty && $this->hasOnlyDeclarations($statements)) {
            return unlink($file);
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
        };
        $fixedCode     = $prettyPrinter->printFormatPreserving($statements, $originalStatements, $tokens);

        return $fixedCode !== $code && file_put_contents($file, $fixedCode) !== false;
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
