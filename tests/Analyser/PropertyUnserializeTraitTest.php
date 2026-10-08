<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser;

use Boundwize\StructArmed\Analyser\AnonymousClassNode;
use Boundwize\StructArmed\Analyser\AnonymousFunctionNode;
use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\ConstantNode;
use Boundwize\StructArmed\Analyser\EnumCaseNode;
use Boundwize\StructArmed\Analyser\FileAnalysis;
use Boundwize\StructArmed\Analyser\FunctionNode;
use Boundwize\StructArmed\Analyser\MethodNode;
use Boundwize\StructArmed\Analyser\PropertyNode;
use Boundwize\StructArmed\Analyser\PropertyUnserializeTrait;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function serialize;
use function unserialize;

#[CoversTrait(PropertyUnserializeTrait::class)]
final class PropertyUnserializeTraitTest extends TestCase
{
    /**
     * The worker payload carries every node kind, nested members included, so
     * each one must survive serialize() and come back equal property by property.
     */
    #[DataProvider('nodes')]
    public function testNodeSurvivesSerializeRoundTrip(object $node): void
    {
        $copy = unserialize(serialize($node));

        $this->assertInstanceOf($node::class, $copy);
        $this->assertEquals($node, $copy);
    }

    /** @return iterable<string, array{object}> */
    public static function nodes(): iterable
    {
        yield 'method' => [new MethodNode('handle', 'protected', true, true, 2, 3, 12, true, 7, false)];
        yield 'property' => [new PropertyNode('name', 'private', true, 4)];
        yield 'constant' => [new ConstantNode('LIMIT', 'public', true, 3)];
        yield 'enum case' => [new EnumCaseNode('Active', 5, 'active')];
        yield 'file analysis' => [
            new FileAnalysis(
                file: '/src/Foo.php',
                hasUtf8Bom: false,
                hasValidUtf8: true,
                invalidPhpTagLine: null,
                hasValidAst: true,
                declaresSymbols: true,
                hasSideEffects: true,
                sideEffectLine: 9,
                nonCanonicalKeywordConstants: [[9, 'TRUE']],
                numericLiterals: [[11, '1000000', 1000000]],
            ),
        ];
        yield 'class' => [
            new ClassNode(
                className: 'App\Domain\Order',
                file: '/src/Domain/Order.php',
                line: 5,
                layer: 'Domain',
                extends: 'App\Domain\AggregateRoot',
                isAbstract: false,
                isFinal: true,
                isInterface: false,
                isReadonly: false,
                dependencies: ['App\Domain\Money', 'App\Domain\OrderLine'],
                implements: ['JsonSerializable'],
                traits: ['App\Domain\HasEvents'],
                methods: [new MethodNode('total', 'public', true, false, 0, 2, 6, true, 12)],
                constants: [new ConstantNode('STATUS_NEW', 'public', true, 7)],
                properties: [new PropertyNode('lines', 'private', true, 9)],
                functionCalls: ['array_sum'],
                superglobals: ['$_SERVER'],
                languageConstructs: ['exit'],
                layers: ['Domain', 'Core'],
                enumCases: [new EnumCaseNode('Draft', 8, 'draft')],
                nonClassDependencies: ['App\Domain\helper'],
                constantFetches: ['PHP_EOL'],
            ),
        ];
        yield 'function' => [
            new FunctionNode(
                functionName: 'App\Support\format',
                file: '/src/Support/functions.php',
                line: 3,
                layer: 'Support',
                hasReturnType: true,
                paramCount: 1,
                cyclomaticComplexity: 2,
                lineCount: 8,
                dependencies: ['App\Support\Formatter'],
                functionCalls: ['sprintf'],
                layers: ['Support'],
                isReferenced: true,
            ),
        ];
        yield 'anonymous class' => [
            new AnonymousClassNode(
                file: '/src/Infrastructure/Clock.php',
                line: 14,
                extends: null,
                implements: ['App\Domain\Clock'],
                layer: 'Infrastructure',
                enclosingClassName: 'App\Infrastructure\ClockFactory',
                layers: ['Infrastructure'],
                dependencies: ['DateTimeImmutable'],
                methods: [new MethodNode('now', 'public', true, false, 0, 1, 3, true, 16)],
            ),
        ];
        yield 'anonymous function' => [
            new AnonymousFunctionNode(
                file: '/src/Infrastructure/ClockFactory.php',
                line: 20,
                layer: 'Infrastructure',
                isArrowFunction: true,
                isStatic: true,
                enclosingClassName: 'App\Infrastructure\ClockFactory',
                enclosingFunctionName: 'create',
                paramCount: 1,
                dependencies: ['DateTimeImmutable'],
                layers: ['Infrastructure'],
            ),
        ];
    }
}
