<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\PhpParser;

use PhpParser\Error;
use PhpParser\Node;
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
use function unlink;

final readonly class PhpParserFixerProcessor
{
    public function process(string $file, NodeVisitor $nodeVisitor, bool $removeFileWhenEmpty = false): bool
    {
        if (! is_file($file)) {
            return false;
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

        $nameResolver = new NameResolver(options: ['replaceNodes' => false]);
        $statements   = (new NodeTraverser($nameResolver, $nodeVisitor))
            ->traverse((new NodeTraverser(new CloningVisitor()))
            ->traverse($originalStatements));

        // A fix that removes the last declaration leaves only boilerplate
        // (declare/namespace/use); the whole file is dead weight at that point.
        if ($removeFileWhenEmpty && $this->hasOnlyDeclarations($statements)) {
            return unlink($file);
        }

        $fixedCode = (new Standard())->printFormatPreserving($statements, $originalStatements, $parser->getTokens());

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
