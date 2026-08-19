<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\ExtendedClassAwareRuleInterface;
use Boundwize\StructArmed\Rule\FixableInterface;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\Class_\AddAbstractClassVisitor;
use Boundwize\StructArmed\Rule\Rules\Class_\ExtendedClassMustBeAbstractOrReferencedRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use function file_get_contents;
use function file_put_contents;

#[CoversClass(ExtendedClassMustBeAbstractOrReferencedRule::class)]
#[CoversClass(AddAbstractClassVisitor::class)]
final class ExtendedClassMustBeAbstractOrReferencedRuleTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    private function makeNode(
        string $className = 'App\\Domain\\BaseRepository',
        string $layer = 'Domain',
        bool $isAbstract = false,
        bool $isInterface = false,
        bool $isTrait = false,
        bool $isEnum = false,
        bool $isExtended = false,
        bool $isReferenced = false,
    ): ClassNode {
        return new ClassNode(
            className:   $className,
            file:        '/src/Domain/BaseRepository.php',
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
            isReferenced: $isReferenced,
        );
    }

    public function testViolatesWhenExtendedClassIsNeitherAbstractNorReferenced(): void
    {
        $extendedClassMustBeAbstractOrReferencedRule = new ExtendedClassMustBeAbstractOrReferencedRule(
            layer: 'Domain'
        );
        $classNode                                   = $this->makeNode(isExtended: true);

        $violation = $extendedClassMustBeAbstractOrReferencedRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('must be declared abstract', $violation->message);
    }

    public function testPassesWhenClassIsNotExtended(): void
    {
        $extendedClassMustBeAbstractOrReferencedRule = new ExtendedClassMustBeAbstractOrReferencedRule(
            layer: 'Domain'
        );
        $classNode                                   = $this->makeNode(isExtended: false);

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $extendedClassMustBeAbstractOrReferencedRule->evaluate($classNode)
        );
    }

    public function testPassesWhenExtendedClassIsReferenced(): void
    {
        $extendedClassMustBeAbstractOrReferencedRule = new ExtendedClassMustBeAbstractOrReferencedRule(
            layer: 'Domain'
        );
        $classNode                                   = $this->makeNode(isExtended: true, isReferenced: true);

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $extendedClassMustBeAbstractOrReferencedRule->evaluate($classNode)
        );
    }

    public function testIsExtendedClassAware(): void
    {
        $this->assertInstanceOf(
            ExtendedClassAwareRuleInterface::class,
            new ExtendedClassMustBeAbstractOrReferencedRule(layer: 'Domain')
        );
    }

    public function testIsFixable(): void
    {
        $this->assertInstanceOf(
            FixableInterface::class,
            new ExtendedClassMustBeAbstractOrReferencedRule(layer: 'Domain')
        );
    }

    public function testCreatesAddAbstractClassFixerVisitor(): void
    {
        $extendedClassMustBeAbstractOrReferencedRule = new ExtendedClassMustBeAbstractOrReferencedRule(
            layer: 'Domain'
        );
        $reflectionMethod                            = new ReflectionMethod(
            $extendedClassMustBeAbstractOrReferencedRule,
            'createFixerVisitor'
        );
        $addAbstractClassVisitor                     = $reflectionMethod->invoke(
            $extendedClassMustBeAbstractOrReferencedRule,
            new RuleViolation(
                message:   'Extended class [App\\BaseRepository] must be declared abstract'
                    . ' or referenced as a dependency',
                file:      '/src/BaseRepository.php',
                line:      1,
                className: 'App\\BaseRepository',
                layer:     'Domain',
            )
        );

        $this->assertInstanceOf(AddAbstractClassVisitor::class, $addAbstractClassVisitor);
    }

    public function testDoesNotApplyToWrongLayer(): void
    {
        $extendedClassMustBeAbstractOrReferencedRule = new ExtendedClassMustBeAbstractOrReferencedRule(
            layer: 'Domain'
        );
        $classNode                                   = $this->makeNode(layer: 'Infrastructure');

        $this->assertFalse($extendedClassMustBeAbstractOrReferencedRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToAbstractClasses(): void
    {
        $extendedClassMustBeAbstractOrReferencedRule = new ExtendedClassMustBeAbstractOrReferencedRule(
            layer: 'Domain'
        );
        $classNode                                   = $this->makeNode(isAbstract: true);

        $this->assertFalse($extendedClassMustBeAbstractOrReferencedRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToInterfaces(): void
    {
        $extendedClassMustBeAbstractOrReferencedRule = new ExtendedClassMustBeAbstractOrReferencedRule(
            layer: 'Domain'
        );
        $classNode                                   = $this->makeNode(isInterface: true);

        $this->assertFalse($extendedClassMustBeAbstractOrReferencedRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToTraits(): void
    {
        $extendedClassMustBeAbstractOrReferencedRule = new ExtendedClassMustBeAbstractOrReferencedRule(
            layer: 'Domain'
        );
        $classNode                                   = $this->makeNode(isTrait: true);

        $this->assertFalse($extendedClassMustBeAbstractOrReferencedRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToEnums(): void
    {
        $extendedClassMustBeAbstractOrReferencedRule = new ExtendedClassMustBeAbstractOrReferencedRule(
            layer: 'Domain'
        );
        $classNode                                   = $this->makeNode(isEnum: true);

        $this->assertFalse($extendedClassMustBeAbstractOrReferencedRule->appliesTo($classNode));
    }

    public function testAppliesToMatchingPattern(): void
    {
        $extendedClassMustBeAbstractOrReferencedRule = new ExtendedClassMustBeAbstractOrReferencedRule(
            layer:            'Domain',
            classNamePattern: '/Repository$/'
        );
        $classNode                                   = $this->makeNode();

        $this->assertTrue($extendedClassMustBeAbstractOrReferencedRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToNonMatchingPattern(): void
    {
        $extendedClassMustBeAbstractOrReferencedRule = new ExtendedClassMustBeAbstractOrReferencedRule(
            layer:            'Domain',
            classNamePattern: '/Service$/'
        );
        $classNode                                   = $this->makeNode();

        $this->assertFalse($extendedClassMustBeAbstractOrReferencedRule->appliesTo($classNode));
    }

    public function testAppliesToLayerWhenNoPatternConfigured(): void
    {
        $extendedClassMustBeAbstractOrReferencedRule = new ExtendedClassMustBeAbstractOrReferencedRule(
            layer: 'Domain'
        );
        $classNode                                   = $this->makeNode();

        $this->assertTrue($extendedClassMustBeAbstractOrReferencedRule->appliesTo($classNode));
    }

    public function testFixDeclaresClassAbstract(): void
    {
        $temporaryDirectory = $this->makeTemporaryDirectory('structarmed-yagni-extended');
        $file               = $temporaryDirectory . '/SomeBaseClass.php';

        file_put_contents(
            $file,
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nclass SomeBaseClass\n{\n}\n"
        );

        $extendedClassMustBeAbstractOrReferencedRule = new ExtendedClassMustBeAbstractOrReferencedRule(
            layer: 'Domain'
        );

        $this->assertTrue($extendedClassMustBeAbstractOrReferencedRule->fix(new RuleViolation(
            message:   'Extended class [App\\SomeBaseClass] must be declared abstract'
                . ' or referenced as a dependency',
            file:      $file,
            line:      7,
            className: 'App\\SomeBaseClass',
            layer:     'Domain',
        )));
        $this->assertFileExists($file);
        $this->assertStringContainsString(
            'abstract class SomeBaseClass',
            (string) file_get_contents($file)
        );
    }
}
