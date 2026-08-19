<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Rule\Fixer\PhpParser\Class_\AddAbstractClassVisitor;
use Boundwize\StructArmed\Rule\Rules\Class_\ExtendedClassMustBeAbstractOrInstantiatedRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;

#[CoversClass(ExtendedClassMustBeAbstractOrInstantiatedRule::class)]
#[CoversClass(AddAbstractClassVisitor::class)]
final class ExtendedClassMustBeAbstractOrInstantiatedRuleFixTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testFixDeclaresClassAbstract(): void
    {
        $temporaryDirectory = $this->makeTemporaryDirectory('structarmed-yagni-extended');
        $file               = $temporaryDirectory . '/SomeBaseClass.php';

        file_put_contents(
            $file,
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nclass SomeBaseClass\n{\n}\n"
        );

        $extendedClassMustBeAbstractOrInstantiatedRule = new ExtendedClassMustBeAbstractOrInstantiatedRule(
            layer: 'Domain'
        );

        $this->assertTrue($extendedClassMustBeAbstractOrInstantiatedRule->fix(new RuleViolation(
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
