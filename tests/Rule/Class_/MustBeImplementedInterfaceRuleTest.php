<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\FixableInterface;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\AbstractPhpParserFixableRule;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\ClassLike\RemoveClassLikeVisitor;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\PhpParserFixerProcessor;
use Boundwize\StructArmed\Rule\ImplementedInterfaceAwareRuleInterface;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeImplementedInterfaceRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use function file_get_contents;
use function file_put_contents;

#[CoversClass(MustBeImplementedInterfaceRule::class)]
#[CoversClass(RemoveClassLikeVisitor::class)]
#[CoversClass(AbstractPhpParserFixableRule::class)]
#[CoversClass(PhpParserFixerProcessor::class)]
final class MustBeImplementedInterfaceRuleTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    private function makeNode(
        string $className = 'App\\Domain\\OrderRepositoryInterface',
        string $layer = 'Domain',
        bool $isInterface = true,
        bool $isTrait = false,
        bool $isEnum = false,
        bool $isImplemented = false,
    ): ClassNode {
        return new ClassNode(
            className:    $className,
            file:         '/src/Domain/OrderRepositoryInterface.php',
            line:         1,
            layer:        $layer,
            extends:      null,
            isAbstract:   false,
            isFinal:      false,
            isInterface:  $isInterface,
            isReadonly:   false,
            isTrait:      $isTrait,
            isEnum:       $isEnum,
            isImplemented: $isImplemented,
        );
    }

    public function testPassesWhenInterfaceIsImplemented(): void
    {
        $mustBeImplementedInterfaceRule = new MustBeImplementedInterfaceRule(layer: 'Domain');
        $classNode                      = $this->makeNode(isImplemented: true);

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $mustBeImplementedInterfaceRule->evaluate($classNode)
        );
    }

    public function testViolatesWhenInterfaceIsNotImplemented(): void
    {
        $mustBeImplementedInterfaceRule = new MustBeImplementedInterfaceRule(layer: 'Domain');
        $classNode                      = $this->makeNode(isImplemented: false);

        $violation = $mustBeImplementedInterfaceRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('must be implemented', $violation->message);
    }

    public function testIsImplementedInterfaceAware(): void
    {
        $this->assertInstanceOf(
            ImplementedInterfaceAwareRuleInterface::class,
            new MustBeImplementedInterfaceRule(layer: 'Domain')
        );
    }

    public function testIsFixable(): void
    {
        $this->assertInstanceOf(FixableInterface::class, new MustBeImplementedInterfaceRule(layer: 'Domain'));
    }

    public function testCreatesRemoveClassLikeFixerVisitor(): void
    {
        $mustBeImplementedInterfaceRule = new MustBeImplementedInterfaceRule(layer: 'Domain');
        $reflectionMethod               = new ReflectionMethod($mustBeImplementedInterfaceRule, 'createFixerVisitor');
        $removeClassLikeVisitor         = $reflectionMethod->invoke(
            $mustBeImplementedInterfaceRule,
            new RuleViolation(
                message:   'Interface [App\\Unused] must be implemented by a class or extended by another interface',
                file:      '/src/Unused.php',
                line:      1,
                className: 'App\\Unused',
                layer:     'Domain',
            )
        );

        $this->assertInstanceOf(RemoveClassLikeVisitor::class, $removeClassLikeVisitor);
    }

    public function testDoesNotApplyToWrongLayer(): void
    {
        $mustBeImplementedInterfaceRule = new MustBeImplementedInterfaceRule(layer: 'Domain');
        $classNode                      = $this->makeNode(layer: 'Infrastructure');

        $this->assertFalse($mustBeImplementedInterfaceRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToClasses(): void
    {
        $mustBeImplementedInterfaceRule = new MustBeImplementedInterfaceRule(layer: 'Domain');
        $classNode                      = $this->makeNode(isInterface: false);

        $this->assertFalse($mustBeImplementedInterfaceRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToTraits(): void
    {
        $mustBeImplementedInterfaceRule = new MustBeImplementedInterfaceRule(layer: 'Domain');
        $classNode                      = $this->makeNode(isInterface: false, isTrait: true);

        $this->assertFalse($mustBeImplementedInterfaceRule->appliesTo($classNode));
    }

    public function testAppliesToLayerWhenNoPatternConfigured(): void
    {
        $mustBeImplementedInterfaceRule = new MustBeImplementedInterfaceRule(layer: 'Domain');
        $classNode                      = $this->makeNode();

        $this->assertTrue($mustBeImplementedInterfaceRule->appliesTo($classNode));
    }

    public function testAppliesToMatchingPattern(): void
    {
        $mustBeImplementedInterfaceRule = new MustBeImplementedInterfaceRule(
            layer:            'Domain',
            classNamePattern: '/Interface$/'
        );
        $classNode                      = $this->makeNode(className: 'App\\Domain\\OrderRepositoryInterface');

        $this->assertTrue($mustBeImplementedInterfaceRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToNonMatchingPattern(): void
    {
        $mustBeImplementedInterfaceRule = new MustBeImplementedInterfaceRule(
            layer:            'Domain',
            classNamePattern: '/Repository$/'
        );
        $classNode                      = $this->makeNode(className: 'App\\Domain\\OrderRepositoryInterface');

        $this->assertFalse($mustBeImplementedInterfaceRule->appliesTo($classNode));
    }

    public function testFixDeletesFileWhenOnlyBoilerplateRemains(): void
    {
        $temporaryDirectory = $this->makeTemporaryDirectory('structarmed-yagni-interface');
        $file               = $temporaryDirectory . '/UnusedInterface.php';

        file_put_contents(
            $file,
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\ninterface UnusedInterface\n{\n}\n"
        );

        $mustBeImplementedInterfaceRule = new MustBeImplementedInterfaceRule(layer: 'Domain');

        $this->assertTrue($mustBeImplementedInterfaceRule->fix(new RuleViolation(
            message:   'Interface [App\\UnusedInterface] must be implemented by a class'
                . ' or extended by another interface',
            file:      $file,
            line:      7,
            className: 'App\\UnusedInterface',
            layer:     'Domain',
        )));
        $this->assertFileDoesNotExist($file);
    }

    public function testFixKeepsFileWhenOtherCodeRemains(): void
    {
        $temporaryDirectory = $this->makeTemporaryDirectory('structarmed-yagni-interface');
        $file               = $temporaryDirectory . '/Contracts.php';

        file_put_contents(
            $file,
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\n"
                . "interface UnusedInterface\n{\n}\n\nfinal class Order\n{\n}\n"
        );

        $mustBeImplementedInterfaceRule = new MustBeImplementedInterfaceRule(layer: 'Domain');

        $this->assertTrue($mustBeImplementedInterfaceRule->fix(new RuleViolation(
            message:   'Interface [App\\UnusedInterface] must be implemented by a class'
                . ' or extended by another interface',
            file:      $file,
            line:      7,
            className: 'App\\UnusedInterface',
            layer:     'Domain',
        )));
        $this->assertFileExists($file);

        $fixedCode = (string) file_get_contents($file);

        $this->assertStringNotContainsString('interface UnusedInterface', $fixedCode);
        $this->assertStringContainsString('final class Order', $fixedCode);
    }
}
