<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Layer;

use Boundwize\StructArmed\Analyser\ClassNode;
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
}
