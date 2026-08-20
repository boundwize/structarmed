<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Rule\Fixer\PhpParser\ClassLike\RemoveClassLikeVisitor;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeUsedAbstractClassRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_put_contents;

#[CoversClass(MustBeUsedAbstractClassRule::class)]
#[CoversClass(RemoveClassLikeVisitor::class)]
final class MustBeUsedAbstractClassRuleFixTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

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
