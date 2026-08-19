<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\FixableInterface;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\AbstractPhpParserFixableRule;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\ClassLike\RemoveClassLikeVisitor;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\PhpParserFixerProcessor;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeUsedInterfaceRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Rule\UsedInterfaceAwareRuleInterface;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use function file_get_contents;
use function file_put_contents;

#[CoversClass(MustBeUsedInterfaceRule::class)]
#[CoversClass(RemoveClassLikeVisitor::class)]
#[CoversClass(AbstractPhpParserFixableRule::class)]
#[CoversClass(PhpParserFixerProcessor::class)]
final class MustBeUsedInterfaceRuleTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    private function makeNode(
        string $className = 'App\\Domain\\OrderRepositoryInterface',
        string $layer = 'Domain',
        bool $isInterface = true,
        bool $isTrait = false,
        bool $isEnum = false,
        bool $isImplemented = false,
        bool $isReferenced = false,
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
            isReferenced:       $isReferenced,
        );
    }

    public function testPassesWhenInterfaceIsImplemented(): void
    {
        $mustBeUsedInterfaceRule = new MustBeUsedInterfaceRule(layer: 'Domain');
        $classNode               = $this->makeNode(isImplemented: true);

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $mustBeUsedInterfaceRule->evaluate($classNode)
        );
    }

    public function testPassesWhenInterfaceIsReferencedAsDependency(): void
    {
        $mustBeUsedInterfaceRule = new MustBeUsedInterfaceRule(layer: 'Domain');
        $classNode               = $this->makeNode(isReferenced: true);

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $mustBeUsedInterfaceRule->evaluate($classNode)
        );
    }

    public function testViolatesWhenInterfaceIsNotImplemented(): void
    {
        $mustBeUsedInterfaceRule = new MustBeUsedInterfaceRule(layer: 'Domain');
        $classNode               = $this->makeNode(isImplemented: false);

        $violation = $mustBeUsedInterfaceRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('must be implemented', $violation->message);
    }

    public function testIsUsedInterfaceAware(): void
    {
        $this->assertInstanceOf(
            UsedInterfaceAwareRuleInterface::class,
            new MustBeUsedInterfaceRule(layer: 'Domain')
        );
    }

    public function testIsFixable(): void
    {
        $this->assertInstanceOf(FixableInterface::class, new MustBeUsedInterfaceRule(layer: 'Domain'));
    }

    public function testCreatesRemoveClassLikeFixerVisitor(): void
    {
        $mustBeUsedInterfaceRule = new MustBeUsedInterfaceRule(layer: 'Domain');
        $reflectionMethod        = new ReflectionMethod($mustBeUsedInterfaceRule, 'createFixerVisitor');
        $removeClassLikeVisitor  = $reflectionMethod->invoke(
            $mustBeUsedInterfaceRule,
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
        $mustBeUsedInterfaceRule = new MustBeUsedInterfaceRule(layer: 'Domain');
        $classNode               = $this->makeNode(layer: 'Infrastructure');

        $this->assertFalse($mustBeUsedInterfaceRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToClasses(): void
    {
        $mustBeUsedInterfaceRule = new MustBeUsedInterfaceRule(layer: 'Domain');
        $classNode               = $this->makeNode(isInterface: false);

        $this->assertFalse($mustBeUsedInterfaceRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToTraits(): void
    {
        $mustBeUsedInterfaceRule = new MustBeUsedInterfaceRule(layer: 'Domain');
        $classNode               = $this->makeNode(isInterface: false, isTrait: true);

        $this->assertFalse($mustBeUsedInterfaceRule->appliesTo($classNode));
    }

    public function testAppliesToLayerWhenNoPatternConfigured(): void
    {
        $mustBeUsedInterfaceRule = new MustBeUsedInterfaceRule(layer: 'Domain');
        $classNode               = $this->makeNode();

        $this->assertTrue($mustBeUsedInterfaceRule->appliesTo($classNode));
    }

    public function testAppliesToMatchingPattern(): void
    {
        $mustBeUsedInterfaceRule = new MustBeUsedInterfaceRule(
            layer:            'Domain',
            classNamePattern: '/Interface$/'
        );
        $classNode               = $this->makeNode(className: 'App\\Domain\\OrderRepositoryInterface');

        $this->assertTrue($mustBeUsedInterfaceRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToNonMatchingPattern(): void
    {
        $mustBeUsedInterfaceRule = new MustBeUsedInterfaceRule(
            layer:            'Domain',
            classNamePattern: '/Repository$/'
        );
        $classNode               = $this->makeNode(className: 'App\\Domain\\OrderRepositoryInterface');

        $this->assertFalse($mustBeUsedInterfaceRule->appliesTo($classNode));
    }

    public function testFixDeletesFileWhenOnlyBoilerplateRemains(): void
    {
        $temporaryDirectory = $this->makeTemporaryDirectory('structarmed-yagni-interface');
        $file               = $temporaryDirectory . '/UnusedInterface.php';

        file_put_contents(
            $file,
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nuse ArrayAccess;\n\n"
                . "interface UnusedInterface extends ArrayAccess\n{\n}\n"
        );

        $mustBeUsedInterfaceRule = new MustBeUsedInterfaceRule(layer: 'Domain');

        $this->assertTrue($mustBeUsedInterfaceRule->fix(new RuleViolation(
            message:   'Interface [App\\UnusedInterface] must be implemented by a class'
                . ' or extended by another interface',
            file:      $file,
            line:      7,
            className: 'App\\UnusedInterface',
            layer:     'Domain',
        )));
        $this->assertFileDoesNotExist($file);
    }

    public function testFixKeepsFileWhenDeclareBlockContainsExecutableCode(): void
    {
        $temporaryDirectory = $this->makeTemporaryDirectory('structarmed-yagni-interface');
        $file               = $temporaryDirectory . '/ticks.php';

        // The block form `declare(ticks=1) { ... }` carries executable
        // statements — removing the interface must not delete the file.
        file_put_contents(
            $file,
            "<?php\n\ndeclare(ticks=1) {\n    echo 'KEEP ME';\n}\n\ninterface UnusedInterface\n{\n}\n"
        );

        $mustBeUsedInterfaceRule = new MustBeUsedInterfaceRule(layer: 'Domain');

        $this->assertTrue($mustBeUsedInterfaceRule->fix(new RuleViolation(
            message:   'Interface [UnusedInterface] must be implemented by a class'
                . ' or extended by another interface',
            file:      $file,
            line:      7,
            className: 'UnusedInterface',
            layer:     'Domain',
        )));
        $this->assertFileExists($file);

        $fixedCode = (string) file_get_contents($file);

        $this->assertStringNotContainsString('interface UnusedInterface', $fixedCode);
        $this->assertStringContainsString("echo 'KEEP ME';", $fixedCode);
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

        $mustBeUsedInterfaceRule = new MustBeUsedInterfaceRule(layer: 'Domain');

        $this->assertTrue($mustBeUsedInterfaceRule->fix(new RuleViolation(
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
