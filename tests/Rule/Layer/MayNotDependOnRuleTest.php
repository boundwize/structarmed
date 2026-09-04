<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Layer;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\AbstractLayerAwareRule;
use Boundwize\StructArmed\Rule\Rules\Layer\MayNotDependOnRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MayNotDependOnRule::class)]
final class MayNotDependOnRuleTest extends TestCase
{
    /**
     * @param list<string> $dependencies
     * @param list<string> $layers
     */
    private function makeNode(
        ?string $layer,
        array $dependencies = [],
        string $className = 'App\\Domain\\OrderService',
        array $layers = [],
        bool $isInterface = false,
        bool $isTrait = false,
        bool $isEnum = false,
    ): ClassNode {
        return new ClassNode(
            className:    $className,
            file:         '/fake.php',
            line:         1,
            layer:        $layer,
            extends:      null,
            isAbstract:   false,
            isFinal:      true,
            isInterface:  $isInterface,
            isReadonly:   false,
            isTrait:      $isTrait,
            dependencies: $dependencies,
            layers:       $layers,
            isEnum:       $isEnum,
        );
    }

    public function testPassesWhenNoDependencyOnForbiddenLayer(): void
    {
        $mayNotDependOnRule = new MayNotDependOnRule(
            from:   'Domain',
            to:     'Infrastructure',
            toPath: 'Infrastructure'
        );
        $classNode          = $this->makeNode('Domain', [
            'App\Domain\Order',
            'App\Domain\OrderRepository',
        ]);

        $this->assertNotInstanceOf(RuleViolation::class, $mayNotDependOnRule->evaluate($classNode));
    }

    public function testViolatesWhenDependencyOnForbiddenLayer(): void
    {
        $mayNotDependOnRule = new MayNotDependOnRule(
            from:   'Domain',
            to:     'Infrastructure',
            toPath: 'Infrastructure'
        );
        $classNode          = $this->makeNode('Domain', [
            'App\Infrastructure\Persistence\DoctrineOrderRepository',
        ]);

        $violation = $mayNotDependOnRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(
            'Class [App\\Domain\\OrderService] in layer [Domain] must not depend on '
            . '[App\\Infrastructure\\Persistence\\DoctrineOrderRepository] which belongs to layer [Infrastructure]',
            $violation->message
        );
    }

    #[DataProvider('nonClassKindProvider')]
    public function testViolationMessageNamesTheClassLikeKind(
        string $expectedKind,
        bool $isInterface,
        bool $isTrait,
        bool $isEnum,
    ): void {
        $mayNotDependOnRule = new MayNotDependOnRule(
            from:   'Domain',
            to:     'Infrastructure',
            toPath: 'Infrastructure'
        );
        $classNode          = $this->makeNode(
            'Domain',
            ['App\\Infrastructure\\Persistence\\DoctrineOrderRepository'],
            isInterface: $isInterface,
            isTrait: $isTrait,
            isEnum: $isEnum,
        );

        $violation = $mayNotDependOnRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(
            $expectedKind . ' [App\\Domain\\OrderService] in layer [Domain] must not depend on '
            . '[App\\Infrastructure\\Persistence\\DoctrineOrderRepository] which belongs to layer [Infrastructure]',
            $violation->message
        );
    }

    /** @return iterable<string, array{string, bool, bool, bool}> */
    public static function nonClassKindProvider(): iterable
    {
        yield 'interface' => ['Interface', true, false, false];
        yield 'trait'     => ['Trait', false, true, false];
        yield 'enum'      => ['Enum', false, false, true];
    }

    public function testDoesNotApplyToWrongSourceLayer(): void
    {
        $mayNotDependOnRule = new MayNotDependOnRule(
            from:   'Domain',
            to:     'Infrastructure',
            toPath: 'Infrastructure'
        );
        $classNode          = $this->makeNode('Application', [
            'App\Infrastructure\Cache\RedisCache',
        ]);

        $this->assertFalse($mayNotDependOnRule->appliesTo($classNode));
    }

    public function testAppliesToCorrectSourceLayer(): void
    {
        $mayNotDependOnRule = new MayNotDependOnRule(
            from:   'Domain',
            to:     'Infrastructure',
            toPath: 'Infrastructure'
        );
        $classNode          = $this->makeNode('Domain');

        $this->assertTrue($mayNotDependOnRule->appliesTo($classNode));
    }

    public function testReportsMultipleViolationsWhenMultipleForbiddenDependencies(): void
    {
        $mayNotDependOnRule = new MayNotDependOnRule(
            from:   'Domain',
            to:     'Infrastructure',
            toPath: 'Infrastructure'
        );
        $classNode          = $this->makeNode('Domain', [
            'App\Infrastructure\A',
            'App\Infrastructure\B',
        ]);

        $violations = $mayNotDependOnRule->evaluateAll($classNode);

        $this->assertCount(2, $violations);
        $this->assertStringContainsString('App\Infrastructure\A', $violations[0]->message);
        $this->assertStringContainsString('App\Infrastructure\B', $violations[1]->message);
    }

    public function testExtendsAbstractLayerAwareRule(): void
    {
        $this->assertInstanceOf(
            AbstractLayerAwareRule::class,
            new MayNotDependOnRule(from: 'Domain', to: 'Infrastructure')
        );
    }

    public function testViolatesUsingClassNodeMapWhenInjected(): void
    {
        $mayNotDependOnRule = new MayNotDependOnRule(from: 'Domain', to: 'Infrastructure');
        $dependencyNode     = $this->makeNode(
            layer:     'Infrastructure',
            className: 'App\Infrastructure\Persistence\DoctrineOrderRepository',
        );
        $mayNotDependOnRule->injectClassNodeMap([$dependencyNode->className => $dependencyNode]);
        $classNode = $this->makeNode('Domain', [
            'App\Infrastructure\Persistence\DoctrineOrderRepository',
        ]);

        $violation = $mayNotDependOnRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('Infrastructure', $violation->message);
    }

    public function testPassesUsingClassNodeMapWhenDependencyNotInForbiddenLayer(): void
    {
        $mayNotDependOnRule = new MayNotDependOnRule(from: 'Domain', to: 'Infrastructure');
        $dependencyNode     = $this->makeNode(layer: 'Domain', className: 'App\Domain\Order');
        $mayNotDependOnRule->injectClassNodeMap([$dependencyNode->className => $dependencyNode]);
        $classNode = $this->makeNode('Domain', [
            'App\Domain\Order',
        ]);

        $this->assertNotInstanceOf(RuleViolation::class, $mayNotDependOnRule->evaluate($classNode));
    }

    public function testClassNodeMapTakesPrecedenceOverToPath(): void
    {
        // Path would match 'App\Infrastructure\A', but its node is in Domain — no violation.
        $mayNotDependOnRule = new MayNotDependOnRule(
            from:   'Domain',
            to:     'Infrastructure',
            toPath: 'Infrastructure'
        );
        $dependencyNode     = $this->makeNode(layer: 'Domain', className: 'App\Infrastructure\A');
        $mayNotDependOnRule->injectClassNodeMap([$dependencyNode->className => $dependencyNode]);
        $classNode = $this->makeNode('Domain', ['App\Infrastructure\A']);

        $this->assertNotInstanceOf(RuleViolation::class, $mayNotDependOnRule->evaluate($classNode));
    }

    public function testViolatesUsingToAsPathFallbackWhenNoClassNodeMapMatch(): void
    {
        $mayNotDependOnRule = new MayNotDependOnRule(from: 'Domain', to: 'Infrastructure');
        $classNode          = $this->makeNode('Domain', [
            'App\Infrastructure\Persistence\DoctrineOrderRepository',
        ]);

        $this->assertInstanceOf(RuleViolation::class, $mayNotDependOnRule->evaluate($classNode));
    }

    public function testFallsBackToPathWhenScannedDependencyHasNoLayer(): void
    {
        $mayNotDependOnRule = new MayNotDependOnRule(
            from:   'Domain',
            to:     'Infrastructure',
            toPath: 'Infrastructure',
        );
        $dependencyNode     = $this->makeNode(
            layer:     null,
            className: 'App\\Infrastructure\\A',
        );
        $mayNotDependOnRule->injectClassNodeMap([$dependencyNode->className => $dependencyNode]);
        $classNode = $this->makeNode('Domain', ['App\\Infrastructure\\A']);

        $this->assertInstanceOf(RuleViolation::class, $mayNotDependOnRule->evaluate($classNode));
    }

    public function testViolatesWhenDependencyMatchesSecondaryLayerInClassNodeMap(): void
    {
        // UserRepository is stored under primary layer 'Infrastructure' but also matches 'Repository'.
        // The rule forbids 'Repository'; the bug was that only the primary layer was checked.
        $mayNotDependOnRule = new MayNotDependOnRule(from: 'Domain', to: 'Repository');
        $dependencyNode     = $this->makeNode(
            layer:     'Infrastructure',
            className: 'App\Infrastructure\UserRepository',
            layers:    ['Infrastructure', 'Repository'],
        );
        $mayNotDependOnRule->injectClassNodeMap([$dependencyNode->className => $dependencyNode]);
        $classNode = $this->makeNode('Domain', ['App\Infrastructure\UserRepository']);

        $violation = $mayNotDependOnRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('Repository', $violation->message);
    }

    public function testPassesWhenDependencyOnlyMatchesPrimaryLayerNotForbidden(): void
    {
        $mayNotDependOnRule = new MayNotDependOnRule(from: 'Domain', to: 'Repository');
        $dependencyNode     = $this->makeNode(
            layer:     'Infrastructure',
            className: 'App\Infrastructure\Cache\RedisCache',
        );
        $mayNotDependOnRule->injectClassNodeMap([$dependencyNode->className => $dependencyNode]);
        $classNode = $this->makeNode('Domain', ['App\Infrastructure\Cache\RedisCache']);

        $this->assertNotInstanceOf(RuleViolation::class, $mayNotDependOnRule->evaluate($classNode));
    }
}
