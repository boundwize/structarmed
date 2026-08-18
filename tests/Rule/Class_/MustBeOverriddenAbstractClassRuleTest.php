<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\ExtendedClassAwareRuleInterface;
use Boundwize\StructArmed\Rule\FixableInterface;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\ClassLike\RemoveClassLikeVisitor;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeOverriddenAbstractClassRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use function file_put_contents;

#[CoversClass(MustBeOverriddenAbstractClassRule::class)]
#[CoversClass(RemoveClassLikeVisitor::class)]
final class MustBeOverriddenAbstractClassRuleTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    private function makeNode(
        string $className = 'App\\Domain\\AbstractHandler',
        string $layer = 'Domain',
        bool $isAbstract = true,
        bool $isInterface = false,
        bool $isTrait = false,
        bool $isEnum = false,
        bool $isExtended = false,
        bool $isUsed = false,
    ): ClassNode {
        return new ClassNode(
            className:   $className,
            file:        '/src/Domain/AbstractHandler.php',
            line:        1,
            layer:       $layer,
            extends:     null,
            isAbstract:  $isAbstract,
            isFinal:     false,
            isInterface: $isInterface,
            isReadonly:  false,
            isTrait:     $isTrait,
            isEnum:      $isEnum,
            isExtended:  $isExtended,
            isUsed:      $isUsed,
        );
    }

    public function testPassesWhenAbstractClassIsExtended(): void
    {
        $mustBeOverriddenAbstractClassRule = new MustBeOverriddenAbstractClassRule(layer: 'Domain');
        $classNode                         = $this->makeNode(isExtended: true);

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $mustBeOverriddenAbstractClassRule->evaluate($classNode)
        );
    }

    public function testPassesWhenAbstractClassIsReferencedAsDependency(): void
    {
        $mustBeOverriddenAbstractClassRule = new MustBeOverriddenAbstractClassRule(layer: 'Domain');
        $classNode                         = $this->makeNode(isUsed: true);

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $mustBeOverriddenAbstractClassRule->evaluate($classNode)
        );
    }

    public function testViolatesWhenAbstractClassIsNotExtended(): void
    {
        $mustBeOverriddenAbstractClassRule = new MustBeOverriddenAbstractClassRule(layer: 'Domain');
        $classNode                         = $this->makeNode(isExtended: false);

        $violation = $mustBeOverriddenAbstractClassRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('must be extended', $violation->message);
    }

    public function testIsExtendedClassAware(): void
    {
        $this->assertInstanceOf(
            ExtendedClassAwareRuleInterface::class,
            new MustBeOverriddenAbstractClassRule(layer: 'Domain')
        );
    }

    public function testIsFixable(): void
    {
        $this->assertInstanceOf(FixableInterface::class, new MustBeOverriddenAbstractClassRule(layer: 'Domain'));
    }

    public function testCreatesRemoveClassLikeFixerVisitor(): void
    {
        $mustBeOverriddenAbstractClassRule = new MustBeOverriddenAbstractClassRule(layer: 'Domain');
        $reflectionMethod                  = new ReflectionMethod(
            $mustBeOverriddenAbstractClassRule,
            'createFixerVisitor'
        );
        $removeClassLikeVisitor            = $reflectionMethod->invoke(
            $mustBeOverriddenAbstractClassRule,
            new RuleViolation(
                message:   'Abstract class [App\\AbstractHandler] must be extended by a class',
                file:      '/src/AbstractHandler.php',
                line:      1,
                className: 'App\\AbstractHandler',
                layer:     'Domain',
            )
        );

        $this->assertInstanceOf(RemoveClassLikeVisitor::class, $removeClassLikeVisitor);
    }

    public function testDoesNotApplyToWrongLayer(): void
    {
        $mustBeOverriddenAbstractClassRule = new MustBeOverriddenAbstractClassRule(layer: 'Domain');
        $classNode                         = $this->makeNode(layer: 'Infrastructure');

        $this->assertFalse($mustBeOverriddenAbstractClassRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToConcreteClasses(): void
    {
        $mustBeOverriddenAbstractClassRule = new MustBeOverriddenAbstractClassRule(layer: 'Domain');
        $classNode                         = $this->makeNode(isAbstract: false);

        $this->assertFalse($mustBeOverriddenAbstractClassRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToInterfaces(): void
    {
        $mustBeOverriddenAbstractClassRule = new MustBeOverriddenAbstractClassRule(layer: 'Domain');
        $classNode                         = $this->makeNode(isInterface: true);

        $this->assertFalse($mustBeOverriddenAbstractClassRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToTraits(): void
    {
        $mustBeOverriddenAbstractClassRule = new MustBeOverriddenAbstractClassRule(layer: 'Domain');
        $classNode                         = $this->makeNode(isTrait: true);

        $this->assertFalse($mustBeOverriddenAbstractClassRule->appliesTo($classNode));
    }

    public function testAppliesToLayerWhenNoPatternConfigured(): void
    {
        $mustBeOverriddenAbstractClassRule = new MustBeOverriddenAbstractClassRule(layer: 'Domain');
        $classNode                         = $this->makeNode();

        $this->assertTrue($mustBeOverriddenAbstractClassRule->appliesTo($classNode));
    }

    public function testAppliesToMatchingPattern(): void
    {
        $mustBeOverriddenAbstractClassRule = new MustBeOverriddenAbstractClassRule(
            layer:            'Domain',
            classNamePattern: '/^App\\\\Domain\\\\Abstract/'
        );
        $classNode                         = $this->makeNode();

        $this->assertTrue($mustBeOverriddenAbstractClassRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToNonMatchingPattern(): void
    {
        $mustBeOverriddenAbstractClassRule = new MustBeOverriddenAbstractClassRule(
            layer:            'Domain',
            classNamePattern: '/Base$/'
        );
        $classNode                         = $this->makeNode();

        $this->assertFalse($mustBeOverriddenAbstractClassRule->appliesTo($classNode));
    }

    public function testFixDeletesFileWhenOnlyBoilerplateRemains(): void
    {
        $temporaryDirectory = $this->makeTemporaryDirectory('structarmed-yagni-abstract');
        $file               = $temporaryDirectory . '/AbstractHandler.php';

        file_put_contents(
            $file,
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nabstract class AbstractHandler\n{\n}\n"
        );

        $mustBeOverriddenAbstractClassRule = new MustBeOverriddenAbstractClassRule(layer: 'Domain');

        $this->assertTrue($mustBeOverriddenAbstractClassRule->fix(new RuleViolation(
            message:   'Abstract class [App\\AbstractHandler] must be extended by a class',
            file:      $file,
            line:      7,
            className: 'App\\AbstractHandler',
            layer:     'Domain',
        )));
        $this->assertFileDoesNotExist($file);
    }
}
