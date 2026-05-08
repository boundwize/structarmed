<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Layer;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Layer\MayNotDependOnRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MayNotDependOnRule::class)]
final class MayNotDependOnRuleTest extends TestCase
{
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
        $rule = new MayNotDependOnRule(
            from:   'Domain',
            to:     'Infrastructure',
            toPath: 'Infrastructure'
        );
        $node = $this->makeNode('Domain', [
            'App\Domain\Order',
            'App\Domain\OrderRepository',
        ]);

        $this->assertNull($rule->evaluate($node));
    }

    public function testViolatesWhenDependencyOnForbiddenLayer(): void
    {
        $rule = new MayNotDependOnRule(
            from:   'Domain',
            to:     'Infrastructure',
            toPath: 'Infrastructure'
        );
        $node = $this->makeNode('Domain', [
            'App\Infrastructure\Persistence\DoctrineOrderRepository',
        ]);

        $violation = $rule->evaluate($node);

        $this->assertNotNull($violation);
        $this->assertStringContainsString('Infrastructure', $violation->message);
    }

    public function testDoesNotApplyToWrongSourceLayer(): void
    {
        $rule = new MayNotDependOnRule(
            from:   'Domain',
            to:     'Infrastructure',
            toPath: 'Infrastructure'
        );
        $node = $this->makeNode('Application', [
            'App\Infrastructure\Cache\RedisCache',
        ]);

        $this->assertFalse($rule->appliesTo($node));
    }

    public function testAppliesToCorrectSourceLayer(): void
    {
        $rule = new MayNotDependOnRule(
            from:   'Domain',
            to:     'Infrastructure',
            toPath: 'Infrastructure'
        );
        $node = $this->makeNode('Domain');

        $this->assertTrue($rule->appliesTo($node));
    }
}
