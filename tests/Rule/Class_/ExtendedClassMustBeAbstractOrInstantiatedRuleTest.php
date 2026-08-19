<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\ExtendedClassAwareRuleInterface;
use Boundwize\StructArmed\Rule\FixableInterface;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\Class_\AddAbstractClassVisitor;
use Boundwize\StructArmed\Rule\Rules\Class_\ExtendedClassMustBeAbstractOrInstantiatedRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(ExtendedClassMustBeAbstractOrInstantiatedRule::class)]
#[CoversClass(AddAbstractClassVisitor::class)]
final class ExtendedClassMustBeAbstractOrInstantiatedRuleTest extends TestCase
{
    private function makeNode(
        string $className = 'App\\Domain\\BaseRepository',
        string $layer = 'Domain',
        bool $isAbstract = false,
        bool $isInterface = false,
        bool $isTrait = false,
        bool $isEnum = false,
        bool $isExtended = false,
        bool $isReferenced = false,
        bool $isInstantiated = false,
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
            isInstantiated: $isInstantiated,
        );
    }

    public function testViolatesWhenExtendedClassIsNeitherAbstractNorReferenced(): void
    {
        $extendedClassMustBeAbstractOrInstantiatedRule = new ExtendedClassMustBeAbstractOrInstantiatedRule(
            layer: 'Domain'
        );
        $classNode                                     = $this->makeNode(isExtended: true);

        $violation = $extendedClassMustBeAbstractOrInstantiatedRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('must be declared abstract', $violation->message);
    }

    public function testPassesWhenClassIsNotExtended(): void
    {
        $extendedClassMustBeAbstractOrInstantiatedRule = new ExtendedClassMustBeAbstractOrInstantiatedRule(
            layer: 'Domain'
        );
        $classNode                                     = $this->makeNode(isExtended: false);

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $extendedClassMustBeAbstractOrInstantiatedRule->evaluate($classNode)
        );
    }

    public function testPassesWhenExtendedClassIsInstantiated(): void
    {
        $extendedClassMustBeAbstractOrInstantiatedRule = new ExtendedClassMustBeAbstractOrInstantiatedRule(
            layer: 'Domain'
        );
        $classNode                                     = $this->makeNode(isExtended: true, isInstantiated: true);

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $extendedClassMustBeAbstractOrInstantiatedRule->evaluate($classNode)
        );
    }

    public function testViolatesWhenExtendedClassIsOnlyReferencedButNotInstantiated(): void
    {
        // Type hints, instanceof, and ::class keep working on an abstract
        // class, so a plain reference is not enough to keep it concrete.
        $extendedClassMustBeAbstractOrInstantiatedRule = new ExtendedClassMustBeAbstractOrInstantiatedRule(
            layer: 'Domain'
        );
        $classNode                                     = $this->makeNode(isExtended: true, isReferenced: true);

        $this->assertInstanceOf(
            RuleViolation::class,
            $extendedClassMustBeAbstractOrInstantiatedRule->evaluate($classNode)
        );
    }

    public function testIsExtendedClassAware(): void
    {
        $this->assertInstanceOf(
            ExtendedClassAwareRuleInterface::class,
            new ExtendedClassMustBeAbstractOrInstantiatedRule(layer: 'Domain')
        );
    }

    public function testIsFixable(): void
    {
        $this->assertInstanceOf(
            FixableInterface::class,
            new ExtendedClassMustBeAbstractOrInstantiatedRule(layer: 'Domain')
        );
    }

    public function testCreatesAddAbstractClassFixerVisitor(): void
    {
        $extendedClassMustBeAbstractOrInstantiatedRule = new ExtendedClassMustBeAbstractOrInstantiatedRule(
            layer: 'Domain'
        );
        $reflectionMethod                              = new ReflectionMethod(
            $extendedClassMustBeAbstractOrInstantiatedRule,
            'createFixerVisitor'
        );
        $addAbstractClassVisitor                       = $reflectionMethod->invoke(
            $extendedClassMustBeAbstractOrInstantiatedRule,
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
        $extendedClassMustBeAbstractOrInstantiatedRule = new ExtendedClassMustBeAbstractOrInstantiatedRule(
            layer: 'Domain'
        );
        $classNode                                     = $this->makeNode(layer: 'Infrastructure');

        $this->assertFalse($extendedClassMustBeAbstractOrInstantiatedRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToAbstractClasses(): void
    {
        $extendedClassMustBeAbstractOrInstantiatedRule = new ExtendedClassMustBeAbstractOrInstantiatedRule(
            layer: 'Domain'
        );
        $classNode                                     = $this->makeNode(isAbstract: true);

        $this->assertFalse($extendedClassMustBeAbstractOrInstantiatedRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToInterfaces(): void
    {
        $extendedClassMustBeAbstractOrInstantiatedRule = new ExtendedClassMustBeAbstractOrInstantiatedRule(
            layer: 'Domain'
        );
        $classNode                                     = $this->makeNode(isInterface: true);

        $this->assertFalse($extendedClassMustBeAbstractOrInstantiatedRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToTraits(): void
    {
        $extendedClassMustBeAbstractOrInstantiatedRule = new ExtendedClassMustBeAbstractOrInstantiatedRule(
            layer: 'Domain'
        );
        $classNode                                     = $this->makeNode(isTrait: true);

        $this->assertFalse($extendedClassMustBeAbstractOrInstantiatedRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToEnums(): void
    {
        $extendedClassMustBeAbstractOrInstantiatedRule = new ExtendedClassMustBeAbstractOrInstantiatedRule(
            layer: 'Domain'
        );
        $classNode                                     = $this->makeNode(isEnum: true);

        $this->assertFalse($extendedClassMustBeAbstractOrInstantiatedRule->appliesTo($classNode));
    }

    public function testAppliesToMatchingPattern(): void
    {
        $extendedClassMustBeAbstractOrInstantiatedRule = new ExtendedClassMustBeAbstractOrInstantiatedRule(
            layer:            'Domain',
            classNamePattern: '/Repository$/'
        );
        $classNode                                     = $this->makeNode();

        $this->assertTrue($extendedClassMustBeAbstractOrInstantiatedRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToNonMatchingPattern(): void
    {
        $extendedClassMustBeAbstractOrInstantiatedRule = new ExtendedClassMustBeAbstractOrInstantiatedRule(
            layer:            'Domain',
            classNamePattern: '/Service$/'
        );
        $classNode                                     = $this->makeNode();

        $this->assertFalse($extendedClassMustBeAbstractOrInstantiatedRule->appliesTo($classNode));
    }

    public function testAppliesToLayerWhenNoPatternConfigured(): void
    {
        $extendedClassMustBeAbstractOrInstantiatedRule = new ExtendedClassMustBeAbstractOrInstantiatedRule(
            layer: 'Domain'
        );
        $classNode                                     = $this->makeNode();

        $this->assertTrue($extendedClassMustBeAbstractOrInstantiatedRule->appliesTo($classNode));
    }
}
