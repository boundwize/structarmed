<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Rule\Fixer\PhpParser\AbstractPhpParserFixableRule;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\ClassLike\RemoveClassLikeVisitor;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\PhpParserFixerProcessor;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeUsedInterfaceRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;

#[CoversClass(MustBeUsedInterfaceRule::class)]
#[CoversClass(RemoveClassLikeVisitor::class)]
#[CoversClass(AbstractPhpParserFixableRule::class)]
#[CoversClass(PhpParserFixerProcessor::class)]
final class MustBeUsedInterfaceRuleFixTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

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

    public function testBatchFixRunsEveryVisitorBeforeDeletingFile(): void
    {
        $temporaryDirectory = $this->makeTemporaryDirectory('structarmed-yagni-interface');
        $file               = $temporaryDirectory . '/UnusedInterfaces.php';

        file_put_contents(
            $file,
            "<?php\n\nnamespace App;\n\ninterface FirstUnused\n{\n}\n\ninterface SecondUnused\n{\n}\n"
        );

        $rule = new MustBeUsedInterfaceRule(layer: 'Domain');

        $this->assertTrue($rule->fix(
            new RuleViolation(
                message:   'Interface [App\\FirstUnused] must be used',
                file:      $file,
                line:      5,
                className: 'App\\FirstUnused',
                layer:     'Domain',
            ),
            new RuleViolation(
                message:   'Interface [App\\SecondUnused] must be used',
                file:      $file,
                line:      9,
                className: 'App\\SecondUnused',
                layer:     'Domain',
            ),
        ));
        $this->assertFileDoesNotExist($file);

        // A later fixer batch stops at the processor's is_file() guard.
        $this->assertFalse($rule->fix(new RuleViolation(
            message:   'Interface [App\\FirstUnused] must be used',
            file:      $file,
            line:      5,
            className: 'App\\FirstUnused',
            layer:     'Domain',
        )));
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
