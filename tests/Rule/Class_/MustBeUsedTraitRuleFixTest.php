<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Rule\Fixer\PhpParser\ClassLike\RemoveClassLikeVisitor;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeUsedTraitRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_put_contents;

#[CoversClass(MustBeUsedTraitRule::class)]
#[CoversClass(RemoveClassLikeVisitor::class)]
final class MustBeUsedTraitRuleFixTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

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
