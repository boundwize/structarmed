<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\ExtendedClassAwareRuleInterface;
use Boundwize\StructArmed\Rule\FixableInterface;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\ClassLike\RemoveClassLikeVisitor;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeUsedAbstractClassRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use function file_put_contents;

#[CoversClass(MustBeUsedAbstractClassRule::class)]
#[CoversClass(RemoveClassLikeVisitor::class)]
final class MustBeUsedAbstractClassRuleTest extends TestCase
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
        bool $isReferenced = false,
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
            isReferenced:      $isReferenced,
        );
    }

    public function testPassesWhenAbstractClassIsExtended(): void
    {
        $mustBeUsedAbstractClassRule = new MustBeUsedAbstractClassRule(layer: 'Domain');
        $classNode                   = $this->makeNode(isExtended: true);

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $mustBeUsedAbstractClassRule->evaluate($classNode)
        );
    }

    public function testPassesWhenAbstractClassIsReferencedAsDependency(): void
    {
        $mustBeUsedAbstractClassRule = new MustBeUsedAbstractClassRule(layer: 'Domain');
        $classNode                   = $this->makeNode(isReferenced: true);

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $mustBeUsedAbstractClassRule->evaluate($classNode)
        );
    }

    public function testViolatesWhenAbstractClassIsNotExtended(): void
    {
        $mustBeUsedAbstractClassRule = new MustBeUsedAbstractClassRule(layer: 'Domain');
        $classNode                   = $this->makeNode(isExtended: false);

        $violation = $mustBeUsedAbstractClassRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('must be extended', $violation->message);
    }

    public function testIsExtendedClassAware(): void
    {
        $this->assertInstanceOf(
            ExtendedClassAwareRuleInterface::class,
            new MustBeUsedAbstractClassRule(layer: 'Domain')
        );
    }

    public function testIsFixable(): void
    {
        $this->assertInstanceOf(FixableInterface::class, new MustBeUsedAbstractClassRule(layer: 'Domain'));
    }

    public function testCreatesRemoveClassLikeFixerVisitor(): void
    {
        $mustBeUsedAbstractClassRule = new MustBeUsedAbstractClassRule(layer: 'Domain');
        $reflectionMethod            = new ReflectionMethod(
            $mustBeUsedAbstractClassRule,
            'createFixerVisitor'
        );
        $removeClassLikeVisitor      = $reflectionMethod->invoke(
            $mustBeUsedAbstractClassRule,
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
        $mustBeUsedAbstractClassRule = new MustBeUsedAbstractClassRule(layer: 'Domain');
        $classNode                   = $this->makeNode(layer: 'Infrastructure');

        $this->assertFalse($mustBeUsedAbstractClassRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToConcreteClasses(): void
    {
        $mustBeUsedAbstractClassRule = new MustBeUsedAbstractClassRule(layer: 'Domain');
        $classNode                   = $this->makeNode(isAbstract: false);

        $this->assertFalse($mustBeUsedAbstractClassRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToInterfaces(): void
    {
        $mustBeUsedAbstractClassRule = new MustBeUsedAbstractClassRule(layer: 'Domain');
        $classNode                   = $this->makeNode(isInterface: true);

        $this->assertFalse($mustBeUsedAbstractClassRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToTraits(): void
    {
        $mustBeUsedAbstractClassRule = new MustBeUsedAbstractClassRule(layer: 'Domain');
        $classNode                   = $this->makeNode(isTrait: true);

        $this->assertFalse($mustBeUsedAbstractClassRule->appliesTo($classNode));
    }

    public function testAppliesToLayerWhenNoPatternConfigured(): void
    {
        $mustBeUsedAbstractClassRule = new MustBeUsedAbstractClassRule(layer: 'Domain');
        $classNode                   = $this->makeNode();

        $this->assertTrue($mustBeUsedAbstractClassRule->appliesTo($classNode));
    }

    public function testAppliesToMatchingPattern(): void
    {
        $mustBeUsedAbstractClassRule = new MustBeUsedAbstractClassRule(
            layer:            'Domain',
            classNamePattern: '/^App\\\\Domain\\\\Abstract/'
        );
        $classNode                   = $this->makeNode();

        $this->assertTrue($mustBeUsedAbstractClassRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToNonMatchingPattern(): void
    {
        $mustBeUsedAbstractClassRule = new MustBeUsedAbstractClassRule(
            layer:            'Domain',
            classNamePattern: '/Base$/'
        );
        $classNode                   = $this->makeNode();

        $this->assertFalse($mustBeUsedAbstractClassRule->appliesTo($classNode));
    }

    public function testFixDeletesFileWhenOnlyBoilerplateRemains(): void
    {
        $temporaryDirectory = $this->makeTemporaryDirectory('structarmed-yagni-abstract');
        $file               = $temporaryDirectory . '/AbstractHandler.php';

        file_put_contents(
            $file,
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nabstract class AbstractHandler\n{\n}\n"
        );

        $mustBeUsedAbstractClassRule = new MustBeUsedAbstractClassRule(layer: 'Domain');

        $this->assertTrue($mustBeUsedAbstractClassRule->fix(new RuleViolation(
            message:   'Abstract class [App\\AbstractHandler] must be extended by a class',
            file:      $file,
            line:      7,
            className: 'App\\AbstractHandler',
            layer:     'Domain',
        )));
        $this->assertFileDoesNotExist($file);
    }
}
