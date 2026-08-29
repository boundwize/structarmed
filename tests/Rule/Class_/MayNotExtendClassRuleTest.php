<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Class_\MayNotExtendClassRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function strtolower;

#[CoversClass(MayNotExtendClassRule::class)]
final class MayNotExtendClassRuleTest extends TestCase
{
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';

    public function testPassesWhenClassIsNotExtended(): void
    {
        $mayNotExtendClassRule = new MayNotExtendClassRule(layer: 'Domain', class: self::MODEL);

        $this->assertNotInstanceOf(RuleViolation::class, $mayNotExtendClassRule->evaluate($this->makeNode(null)));
    }

    public function testPassesWhenAnotherClassIsExtended(): void
    {
        $mayNotExtendClassRule = new MayNotExtendClassRule(layer: 'Domain', class: self::MODEL);

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $mayNotExtendClassRule->evaluate($this->makeNode('App\\Domain\\AbstractEntity'))
        );
    }

    public function testAppliesOnlyToConfiguredLayer(): void
    {
        $mayNotExtendClassRule = new MayNotExtendClassRule(layer: 'Domain', class: self::MODEL);

        $this->assertTrue($mayNotExtendClassRule->appliesTo($this->makeNode(null)));
        $this->assertFalse($mayNotExtendClassRule->appliesTo($this->makeNode(null, 'Infrastructure')));
    }

    public function testAppliesOnlyToClasses(): void
    {
        $mayNotExtendClassRule = new MayNotExtendClassRule(layer: 'Domain', class: self::MODEL);

        $this->assertFalse($mayNotExtendClassRule->appliesTo($this->makeNode(null, isInterface: true)));
        $this->assertFalse($mayNotExtendClassRule->appliesTo($this->makeNode(null, isTrait: true)));
        $this->assertFalse($mayNotExtendClassRule->appliesTo($this->makeNode(null, isEnum: true)));
    }

    public function testViolatesWhenClassDirectlyExtendsForbiddenClass(): void
    {
        $mayNotExtendClassRule = new MayNotExtendClassRule(layer: 'Domain', class: self::MODEL);

        $violation = $mayNotExtendClassRule->evaluate($this->makeNode(self::MODEL));

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame('Class [App\\Domain\\Order] must not extend class [Illuminate\\Database\\Eloquent\\Model]', $violation->message);
    }

    public function testMatchesClassNameCaseInsensitively(): void
    {
        $mayNotExtendClassRule = new MayNotExtendClassRule(layer: 'Domain', class: strtolower(self::MODEL));

        $this->assertInstanceOf(
            RuleViolation::class,
            $mayNotExtendClassRule->evaluate($this->makeNode(self::MODEL))
        );
    }

    public function testViolatesWhenClassIndirectlyExtendsForbiddenClass(): void
    {
        $mayNotExtendClassRule = new MayNotExtendClassRule(layer: 'Domain', class: self::MODEL);

        // App\Domain\Order extends App\Domain\Entity, which extends the ORM model.
        $classNode = $this->makeNode('App\\Domain\\Entity');
        $classNode->setRecursiveParents(['App\\Domain\\Entity', self::MODEL], []);

        $violation = $mayNotExtendClassRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame('Class [App\\Domain\\Order] must not extend class [Illuminate\\Database\\Eloquent\\Model]', $violation->message);
    }

    private function makeNode(
        ?string $extends,
        string $layer = 'Domain',
        bool $isInterface = false,
        bool $isTrait = false,
        bool $isEnum = false,
    ): ClassNode {
        return new ClassNode(
            className:   'App\\Domain\\Order',
            file:        '/fake.php',
            line:        1,
            layer:       $layer,
            extends:     $extends,
            isAbstract:  false,
            isFinal:     false,
            isInterface: $isInterface,
            isReadonly:  false,
            isTrait:     $isTrait,
            isEnum:      $isEnum,
        );
    }
}
