<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Usage;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Usage\MayNotUseClassRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use DateTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MayNotUseClassRule::class)]
final class MayNotUseClassRuleTest extends TestCase
{
    /** @param list<string> $dependencies */
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
        $mayNotUseClassRule = new MayNotUseClassRule(layer: 'Domain', forbiddenClass: DateTime::class);
        $classNode          = $this->makeNode(['DateTimeImmutable']);

        $this->assertNotInstanceOf(RuleViolation::class, $mayNotUseClassRule->evaluate($classNode));
    }

    public function testPassesWhenDependencyOnlySharesForbiddenClassPrefix(): void
    {
        $mayNotUseClassRule = new MayNotUseClassRule(
            layer: 'Domain',
            forbiddenClass: 'Vendor\\ForbiddenService'
        );
        $classNode          = $this->makeNode(['Vendor\\ForbiddenServiceExtra']);

        $this->assertNotInstanceOf(RuleViolation::class, $mayNotUseClassRule->evaluate($classNode));
    }

    public function testViolatesWhenForbiddenClassIsUsed(): void
    {
        $mayNotUseClassRule = new MayNotUseClassRule(layer: 'Domain', forbiddenClass: DateTime::class);
        $classNode          = $this->makeNode([DateTime::class]);

        $violation = $mayNotUseClassRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('DateTime', $violation->message);
    }

    public function testDoesNotApplyToWrongLayer(): void
    {
        $mayNotUseClassRule = new MayNotUseClassRule(layer: 'Domain', forbiddenClass: DateTime::class);
        $classNode          = $this->makeNode([DateTime::class], layer: 'Infrastructure');

        $this->assertFalse($mayNotUseClassRule->appliesTo($classNode));
    }

    public function testAppliesToMatchingClassNamePattern(): void
    {
        $mayNotUseClassRule = new MayNotUseClassRule(
            layer: 'Domain',
            forbiddenClass: DateTime::class,
            classNamePattern: '/ValueObject$/'
        );
        $classNode          = $this->makeNode([]);

        $this->assertTrue($mayNotUseClassRule->appliesTo($classNode));
    }

    public function testAppliesToMatchingFullyQualifiedClassNamePattern(): void
    {
        $mayNotUseClassRule = new MayNotUseClassRule(
            layer: 'Domain',
            forbiddenClass: DateTime::class,
            classNamePattern: '/^App\\\\Domain\\\\/'
        );
        $classNode          = $this->makeNode([]);

        $this->assertTrue($mayNotUseClassRule->appliesTo($classNode));
    }

    public function testAppliesToLayerWhenNoPatternConfigured(): void
    {
        $mayNotUseClassRule = new MayNotUseClassRule(layer: 'Domain', forbiddenClass: DateTime::class);
        $classNode          = $this->makeNode([]);

        $this->assertTrue($mayNotUseClassRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToNonMatchingClassNamePattern(): void
    {
        $mayNotUseClassRule = new MayNotUseClassRule(
            layer: 'Domain',
            forbiddenClass: DateTime::class,
            classNamePattern: '/Entity$/'
        );
        $classNode          = $this->makeNode([]);

        $this->assertFalse($mayNotUseClassRule->appliesTo($classNode));
    }
}
