<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\FixableInterface;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\ClassLike\RemoveClassLikeVisitor;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeUsedTraitRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Rule\UsedTraitAwareRuleInterface;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use function file_put_contents;

#[CoversClass(MustBeUsedTraitRule::class)]
#[CoversClass(RemoveClassLikeVisitor::class)]
final class MustBeUsedTraitRuleTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    private function makeNode(
        string $className = 'App\\Domain\\TimestampableTrait',
        string $layer = 'Domain',
        bool $isInterface = false,
        bool $isTrait = true,
        bool $isEnum = false,
        bool $isReferenced = false,
    ): ClassNode {
        return new ClassNode(
            className:   $className,
            file:        '/src/Domain/TimestampableTrait.php',
            line:        1,
            layer:       $layer,
            extends:     null,
            isAbstract:  false,
            isFinal:     false,
            isInterface: $isInterface,
            isReadonly:  false,
            isTrait:     $isTrait,
            isEnum:      $isEnum,
            isReferenced:      $isReferenced,
        );
    }

    public function testPassesWhenTraitIsUsed(): void
    {
        $mustBeUsedTraitRule = new MustBeUsedTraitRule(layer: 'Domain');
        $classNode           = $this->makeNode(isReferenced: true);

        $this->assertNotInstanceOf(RuleViolation::class, $mustBeUsedTraitRule->evaluate($classNode));
    }

    public function testViolatesWhenTraitIsNotUsed(): void
    {
        $mustBeUsedTraitRule = new MustBeUsedTraitRule(layer: 'Domain');
        $classNode           = $this->makeNode(isReferenced: false);

        $violation = $mustBeUsedTraitRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('must be used', $violation->message);
    }

    public function testIsUsedTraitAware(): void
    {
        $this->assertInstanceOf(
            UsedTraitAwareRuleInterface::class,
            new MustBeUsedTraitRule(layer: 'Domain')
        );
    }

    public function testIsFixable(): void
    {
        $this->assertInstanceOf(FixableInterface::class, new MustBeUsedTraitRule(layer: 'Domain'));
    }

    public function testCreatesRemoveClassLikeFixerVisitor(): void
    {
        $mustBeUsedTraitRule    = new MustBeUsedTraitRule(layer: 'Domain');
        $reflectionMethod       = new ReflectionMethod($mustBeUsedTraitRule, 'createFixerVisitor');
        $removeClassLikeVisitor = $reflectionMethod->invoke(
            $mustBeUsedTraitRule,
            new RuleViolation(
                message:   'Trait [App\\UnusedTrait] must be used by a class, trait, or enum',
                file:      '/src/UnusedTrait.php',
                line:      1,
                className: 'App\\UnusedTrait',
                layer:     'Domain',
            )
        );

        $this->assertInstanceOf(RemoveClassLikeVisitor::class, $removeClassLikeVisitor);
    }

    public function testDoesNotApplyToWrongLayer(): void
    {
        $mustBeUsedTraitRule = new MustBeUsedTraitRule(layer: 'Domain');
        $classNode           = $this->makeNode(layer: 'Infrastructure');

        $this->assertFalse($mustBeUsedTraitRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToClasses(): void
    {
        $mustBeUsedTraitRule = new MustBeUsedTraitRule(layer: 'Domain');
        $classNode           = $this->makeNode(isTrait: false);

        $this->assertFalse($mustBeUsedTraitRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToInterfaces(): void
    {
        $mustBeUsedTraitRule = new MustBeUsedTraitRule(layer: 'Domain');
        $classNode           = $this->makeNode(isInterface: true, isTrait: false);

        $this->assertFalse($mustBeUsedTraitRule->appliesTo($classNode));
    }

    public function testAppliesToLayerWhenNoPatternConfigured(): void
    {
        $mustBeUsedTraitRule = new MustBeUsedTraitRule(layer: 'Domain');
        $classNode           = $this->makeNode();

        $this->assertTrue($mustBeUsedTraitRule->appliesTo($classNode));
    }

    public function testAppliesToMatchingPattern(): void
    {
        $mustBeUsedTraitRule = new MustBeUsedTraitRule(layer: 'Domain', classNamePattern: '/Trait$/');
        $classNode           = $this->makeNode();

        $this->assertTrue($mustBeUsedTraitRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToNonMatchingPattern(): void
    {
        $mustBeUsedTraitRule = new MustBeUsedTraitRule(layer: 'Domain', classNamePattern: '/Helper$/');
        $classNode           = $this->makeNode();

        $this->assertFalse($mustBeUsedTraitRule->appliesTo($classNode));
    }

    public function testFixDeletesFileWhenOnlyBoilerplateRemains(): void
    {
        $temporaryDirectory = $this->makeTemporaryDirectory('structarmed-yagni-trait');
        $file               = $temporaryDirectory . '/UnusedTrait.php';

        file_put_contents(
            $file,
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\ntrait UnusedTrait\n{\n}\n"
        );

        $mustBeUsedTraitRule = new MustBeUsedTraitRule(layer: 'Domain');

        $this->assertTrue($mustBeUsedTraitRule->fix(new RuleViolation(
            message:   'Trait [App\\UnusedTrait] must be used by a class, trait, or enum',
            file:      $file,
            line:      7,
            className: 'App\\UnusedTrait',
            layer:     'Domain',
        )));
        $this->assertFileDoesNotExist($file);
    }
}
