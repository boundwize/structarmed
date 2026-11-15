<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Usage;

use Boundwize\StructArmed\Analyser\AnalysisNodeCollector;
use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\LayerResolver\Resolvers\NamespaceLayerResolver;
use Boundwize\StructArmed\Rule\Rules\Usage\MayNotCallFunctionRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MayNotCallFunctionRule::class)]
final class MayNotCallFunctionRuleTest extends TestCase
{
    /** @param array<string> $functionCalls */
    private function makeNode(
        array $functionCalls,
        string $layer = 'Domain',
        bool $isTrait = false,
        bool $isEnum = false,
    ): ClassNode {
        return new ClassNode(
            className:     'App\\Domain\\OrderService',
            file:          '/fake.php',
            line:          1,
            layer:         $layer,
            extends:       null,
            isAbstract:    false,
            isFinal:       true,
            isInterface:   false,
            isReadonly:    false,
            isTrait:       $isTrait,
            functionCalls: $functionCalls,
            isEnum:        $isEnum,
        );
    }

    public function testPassesWhenForbiddenFunctionNotCalled(): void
    {
        $mayNotCallFunctionRule = new MayNotCallFunctionRule(layer: 'Domain', function: 'var_dump');
        $classNode              = $this->makeNode(['array_map', 'array_filter']);

        $this->assertNotInstanceOf(RuleViolation::class, $mayNotCallFunctionRule->evaluate($classNode));
    }

    public function testViolatesWhenForbiddenFunctionIsCalled(): void
    {
        $mayNotCallFunctionRule = new MayNotCallFunctionRule(layer: 'Domain', function: 'var_dump');
        $classNode              = $this->makeNode(['var_dump']);

        $violation = $mayNotCallFunctionRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame('Class [App\\Domain\\OrderService] must not call function [var_dump()]', $violation->message);
    }

    #[DataProvider('traitAndEnumKindProvider')]
    public function testViolationMessageNamesTheClassLikeKind(string $expectedKind, bool $isTrait, bool $isEnum): void
    {
        $mayNotCallFunctionRule = new MayNotCallFunctionRule(layer: 'Domain', function: 'var_dump');
        $classNode              = $this->makeNode(['var_dump'], isTrait: $isTrait, isEnum: $isEnum);

        $violation = $mayNotCallFunctionRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(
            $expectedKind . ' [App\\Domain\\OrderService] must not call function [var_dump()]',
            $violation->message
        );
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function traitAndEnumKindProvider(): iterable
    {
        yield 'trait' => ['Trait', true, false];
        yield 'enum'  => ['Enum', false, true];
    }

    public function testFunctionComparisonIsCaseInsensitive(): void
    {
        $mayNotCallFunctionRule = new MayNotCallFunctionRule(layer: 'Domain', function: 'var_dump');
        $classNode              = $this->makeNode(['VAR_DUMP']);

        $this->assertInstanceOf(RuleViolation::class, $mayNotCallFunctionRule->evaluate($classNode));
    }

    public function testViolatesForDdFunction(): void
    {
        $mayNotCallFunctionRule = new MayNotCallFunctionRule(layer: 'Domain', function: 'dd');
        $classNode              = $this->makeNode(['dd']);

        $this->assertInstanceOf(RuleViolation::class, $mayNotCallFunctionRule->evaluate($classNode));
    }

    public function testDoesNotApplyToWrongLayer(): void
    {
        $mayNotCallFunctionRule = new MayNotCallFunctionRule(layer: 'Domain', function: 'var_dump');
        $classNode              = $this->makeNode(['var_dump'], layer: 'Infrastructure');

        $this->assertFalse($mayNotCallFunctionRule->appliesTo($classNode));
    }

    public function testAppliesToCorrectLayer(): void
    {
        $mayNotCallFunctionRule = new MayNotCallFunctionRule(layer: 'Domain', function: 'var_dump');
        $classNode              = $this->makeNode([]);

        $this->assertTrue($mayNotCallFunctionRule->appliesTo($classNode));
    }

    public function testViolatesWhenBacktickOperatorIsUsedAndShellExecIsForbidden(): void
    {
        $classNode = $this->collectNode(<<<'PHP'
            <?php

            namespace App\Domain;

            final class OrderService
            {
                public function files(string $directory): ?string
                {
                    return `ls {$directory}`;
                }
            }
            PHP);

        $mayNotCallFunctionRule = new MayNotCallFunctionRule(layer: 'Domain', function: 'shell_exec');

        $this->assertTrue($mayNotCallFunctionRule->appliesTo($classNode));

        $violation = $mayNotCallFunctionRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(
            'Class [App\\Domain\\OrderService] must not call function [shell_exec()]',
            $violation->message
        );
    }

    private function collectNode(string $code): ClassNode
    {
        $analysisNodeCollector = new AnalysisNodeCollector(
            new NamespaceLayerResolver(['Domain' => 'src/Domain/'], '/fake')
        );
        $parser                = (new ParserFactory())->createForNewestSupportedVersion();
        $ast                   = $parser->parse($code);

        $analysisNodeCollector->setCurrentFile('/fake/src/Domain/OrderService.php', $parser->getTokens());

        (new NodeTraverser(new NameResolver(), $analysisNodeCollector))->traverse($ast ?? []);

        return $analysisNodeCollector->getClassNodes()[0];
    }
}
