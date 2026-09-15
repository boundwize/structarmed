<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Util\PhpParser;

use Boundwize\StructArmed\Util\PhpParser\UnconditionallyDeclaredFunctions;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnconditionallyDeclaredFunctions::class)]
final class UnconditionallyDeclaredFunctionsTest extends TestCase
{
    public function testCollectsOnlyFunctionsDeclaredByLoadingTheFile(): void
    {
        $code = <<<'PHP_WRAP'
        <?php
        namespace App;

        function TopLevel(): void
        {
        }

        declare(ticks=1) {
            function inDeclare(): void
            {
            }
        }

        function outer(): void
        {
            function nested(): void
            {
            }
        }

        if (PHP_VERSION_ID > 0) {
            function conditional(): void
            {
            }
        }

        while (false) {
            function inLoop(): void
            {
            }
        }

        final class Service
        {
            public function method(): void
            {
            }
        }
        PHP_WRAP;

        $parser        = (new ParserFactory())->createForNewestSupportedVersion();
        $nodeTraverser = new NodeTraverser(new NameResolver());
        $nodes         = $nodeTraverser->traverse($parser->parse($code) ?? []);

        $this->assertSame(
            ['app\toplevel' => true, 'app\indeclare' => true, 'app\outer' => true],
            UnconditionallyDeclaredFunctions::names($nodes)
        );
    }

    public function testUsesTheBareNameOutsideAnyNamespace(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $nodes  = $parser->parse('<?php function Helper(): void {}') ?? [];

        $this->assertSame(['helper' => true], UnconditionallyDeclaredFunctions::names($nodes));
    }
}
