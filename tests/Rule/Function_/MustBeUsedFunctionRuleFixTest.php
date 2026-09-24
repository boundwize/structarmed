<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Function_;

use Boundwize\StructArmed\Rule\Fixer\PhpParser\Function_\RemoveFunctionVisitor;
use Boundwize\StructArmed\Rule\Rules\Function_\MustBeUsedFunctionRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;

#[CoversClass(MustBeUsedFunctionRule::class)]
#[CoversClass(RemoveFunctionVisitor::class)]
final class MustBeUsedFunctionRuleFixTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testFixRemovesOnlyTheUnusedFunction(): void
    {
        $temporaryDirectory = $this->makeTemporaryDirectory('structarmed-yagni-function');
        $file               = $temporaryDirectory . '/functions.php';

        file_put_contents($file, <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App;

            function used(): void
            {
            }

            function unused(): void
            {
            }

            PHP);

        $this->assertTrue((new MustBeUsedFunctionRule(layer: 'Source'))->fix($this->violation($file, 'App\\unused')));
        $this->assertSame(<<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App;

            function used(): void
            {
            }

            PHP, file_get_contents($file));
    }

    public function testFixDeletesFileWhenOnlyBoilerplateRemains(): void
    {
        $temporaryDirectory = $this->makeTemporaryDirectory('structarmed-yagni-function');
        $file               = $temporaryDirectory . '/functions.php';

        file_put_contents($file, <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App;

            function unused(): void
            {
            }

            PHP);

        $this->assertTrue((new MustBeUsedFunctionRule(layer: 'Source'))->fix($this->violation($file, 'App\\unused')));
        $this->assertFileDoesNotExist($file);
    }

    public function testFixRemovesEmptiedFunctionExistsGuard(): void
    {
        $temporaryDirectory = $this->makeTemporaryDirectory('structarmed-yagni-function');
        $file               = $temporaryDirectory . '/functions.php';

        file_put_contents($file, <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App;

            if (! function_exists('App\unused')) {
                function unused(): void
                {
                }
            }

            if (! function_exists('App\used')) {
                function used(): void
                {
                }
            }

            PHP);

        $this->assertTrue((new MustBeUsedFunctionRule(layer: 'Source'))->fix($this->violation($file, 'App\\unused')));
        $this->assertSame(<<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App;

            if (! function_exists('App\used')) {
                function used(): void
                {
                }
            }

            PHP, file_get_contents($file));
    }

    public function testFixKeepsFunctionExistsGuardWithElse(): void
    {
        $temporaryDirectory = $this->makeTemporaryDirectory('structarmed-yagni-function');
        $file               = $temporaryDirectory . '/functions.php';

        file_put_contents($file, <<<'PHP'
            <?php

            namespace App;

            if (! function_exists('App\unused')) {
                function unused(): void
                {
                }
            } else {
                echo 'declared';
            }

            PHP);

        $this->assertTrue((new MustBeUsedFunctionRule(layer: 'Source'))->fix($this->violation($file, 'App\\unused')));
        $this->assertSame(<<<'PHP'
            <?php

            namespace App;

            if (! function_exists('App\unused')) {
            } else {
                echo 'declared';
            }

            PHP, file_get_contents($file));
    }

    private function violation(string $file, string $functionName): RuleViolation
    {
        return new RuleViolation(
            message:      'Function [' . $functionName . '()] must be called or referenced as a callable',
            file:         $file,
            line:         1,
            className:    $functionName,
            layer:        'Source',
            functionName: $functionName,
        );
    }
}
