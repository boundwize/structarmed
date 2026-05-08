<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Usage;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Usage\MayNotUseClassRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MayNotUseClassRule::class)]
final class MayNotUseClassRuleTest extends TestCase
{
    private function makeNode(array $dependencies, string $layer = 'Domain'): ClassNode
    {
        return new ClassNode(
            className:    'App\\Domain\\OrderValueObject',
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

    public function testPassesWhenForbiddenClassNotUsed(): void
    {
        $rule = new MayNotUseClassRule(layer: 'Domain', forbiddenClass: \DateTime::class);
        $node = $this->makeNode(['DateTimeImmutable']);

        $this->assertNull($rule->evaluate($node));
    }

    public function testViolatesWhenForbiddenClassIsUsed(): void
    {
        $rule = new MayNotUseClassRule(layer: 'Domain', forbiddenClass: \DateTime::class);
        $node = $this->makeNode([\DateTime::class]);

        $violation = $rule->evaluate($node);

        $this->assertNotNull($violation);
        $this->assertStringContainsString('DateTime', $violation->message);
    }

    public function testDoesNotApplyToWrongLayer(): void
    {
        $rule = new MayNotUseClassRule(layer: 'Domain', forbiddenClass: \DateTime::class);
        $node = $this->makeNode([\DateTime::class], layer: 'Infrastructure');

        $this->assertFalse($rule->appliesTo($node));
    }

    public function testAppliesToMatchingClassNamePattern(): void
    {
        $rule = new MayNotUseClassRule(
            layer: 'Domain',
            forbiddenClass: \DateTime::class,
            classNamePattern: '/ValueObject$/'
        );
        $node = $this->makeNode([]);

        $this->assertTrue($rule->appliesTo($node));
    }

    public function testDoesNotApplyToNonMatchingClassNamePattern(): void
    {
        $rule = new MayNotUseClassRule(
            layer: 'Domain',
            forbiddenClass: \DateTime::class,
            classNamePattern: '/Entity$/'
        );
        $node = $this->makeNode([]);

        $this->assertFalse($rule->appliesTo($node));
    }
}
