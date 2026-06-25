<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer;

use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

use function file_get_contents;
use function file_put_contents;
use function is_file;

final readonly class PhpParserFixerProcessor
{
    public function process(string $file, NodeVisitor $nodeVisitor): bool
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

        $fixedCode = (new Standard())->printFormatPreserving($statements, $originalStatements, $parser->getTokens());

        return $fixedCode !== $code && file_put_contents($file, $fixedCode) !== false;
    }
}
