<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Layer;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\LayerAwareRuleInterface;
use Boundwize\StructArmed\Rule\Rules\Layer\MayNotDependOnRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MayNotDependOnRule::class)]
final class MayNotDependOnRuleTest extends TestCase
{
    /** @param list<string> $dependencies */
    private function makeNode(string $layer, array $dependencies = []): ClassNode
    {
        return new ClassNode(
            className:    'App\\Domain\\OrderService',
            file:         '/fake.php',
            line:         1,
            layer:        $layer,
            extends:      null,
            isAbstract:   false,
            isFinal:      true,
            isInterface:  false,
            isReadonly:   false,
            dependencies: $dependencies,
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
        $this->assertStringContainsString('Infrastructure', $violation->message);
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

    public function testImplementsLayerAwareRuleInterface(): void
    {
        $this->assertInstanceOf(
            LayerAwareRuleInterface::class,
            new MayNotDependOnRule(from: 'Domain', to: 'Infrastructure')
        );
    }

    public function testViolatesUsingClassLayerMapWhenInjected(): void
    {
        $mayNotDependOnRule = new MayNotDependOnRule(from: 'Domain', to: 'Infrastructure');
        $mayNotDependOnRule->injectClassLayerMap([
            'App\Infrastructure\Persistence\DoctrineOrderRepository' => 'Infrastructure',
        ]);
        $classNode = $this->makeNode('Domain', [
            'App\Infrastructure\Persistence\DoctrineOrderRepository',
        ]);

        $violation = $mayNotDependOnRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('Infrastructure', $violation->message);
    }

    public function testPassesUsingClassLayerMapWhenDependencyNotInForbiddenLayer(): void
    {
        $mayNotDependOnRule = new MayNotDependOnRule(from: 'Domain', to: 'Infrastructure');
        $mayNotDependOnRule->injectClassLayerMap([
            'App\Domain\Order' => 'Domain',
        ]);
        $classNode = $this->makeNode('Domain', [
            'App\Domain\Order',
        ]);

        $this->assertNotInstanceOf(RuleViolation::class, $mayNotDependOnRule->evaluate($classNode));
    }

    public function testClassLayerMapTakesPrecedenceOverToPath(): void
    {
        // path would match 'App\Infrastructure\A', but map says it's in Domain — no violation
        $mayNotDependOnRule = new MayNotDependOnRule(
            from:   'Domain',
            to:     'Infrastructure',
            toPath: 'Infrastructure'
        );
        $mayNotDependOnRule->injectClassLayerMap([
            'App\Infrastructure\A' => 'Domain',
        ]);
        $classNode = $this->makeNode('Domain', ['App\Infrastructure\A']);

        $this->assertNotInstanceOf(RuleViolation::class, $mayNotDependOnRule->evaluate($classNode));
    }

    public function testViolatesUsingToAsPathFallbackWhenNoClassLayerMapMatch(): void
    {
        $mayNotDependOnRule = new MayNotDependOnRule(from: 'Domain', to: 'Infrastructure');
        $classNode          = $this->makeNode('Domain', [
            'App\Infrastructure\Persistence\DoctrineOrderRepository',
        ]);

        $this->assertInstanceOf(RuleViolation::class, $mayNotDependOnRule->evaluate($classNode));
    }

    public function testViolatesWhenDependencyMatchesSecondaryLayerInClassLayerMap(): void
    {
        // UserRepository is stored under primary layer 'Infrastructure' but also matches 'Repository'.
        // The rule forbids 'Repository'; the bug was that only the primary layer was checked.
        $mayNotDependOnRule = new MayNotDependOnRule(from: 'Domain', to: 'Repository');
        $mayNotDependOnRule->injectClassLayerMap([
            'App\Infrastructure\UserRepository' => ['Infrastructure', 'Repository'],
        ]);
        $classNode = $this->makeNode('Domain', ['App\Infrastructure\UserRepository']);

        $violation = $mayNotDependOnRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('Repository', $violation->message);
    }

    public function testPassesWhenDependencyOnlyMatchesPrimaryLayerNotForbidden(): void
    {
        $mayNotDependOnRule = new MayNotDependOnRule(from: 'Domain', to: 'Repository');
        $mayNotDependOnRule->injectClassLayerMap([
            'App\Infrastructure\Cache\RedisCache' => ['Infrastructure'],
        ]);
        $classNode = $this->makeNode('Domain', ['App\Infrastructure\Cache\RedisCache']);

        $this->assertNotInstanceOf(RuleViolation::class, $mayNotDependOnRule->evaluate($classNode));
    }
}
