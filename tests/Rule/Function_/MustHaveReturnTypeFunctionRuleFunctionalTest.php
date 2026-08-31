<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Function_;

use Boundwize\StructArmed\Analyser\Analyser;
use Boundwize\StructArmed\Analyser\AnalyserOptions;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\Preset;
use Boundwize\StructArmed\Preset\Presets\MvcPreset;
use Boundwize\StructArmed\Rule\Rules\Function_\MustHaveReturnTypeFunctionRule;
use Boundwize\StructArmed\Rule\RuleViolationCollection;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;

#[CoversClass(MustHaveReturnTypeFunctionRule::class)]
final class MustHaveReturnTypeFunctionRuleFunctionalTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testReportsOneViolationPerFunctionInSingleFile(): void
    {
        // A single FunctionRuleInterface instance is evaluated per function
        // node: one file declaring several untyped functions reports one
        // violation for each of them.
        $basePath = $this->makeTempProject([
            'src/Helper/functions.php' => <<<'PHP'
                <?php

                namespace App\Helper;

                function format_price($amount)
                {
                    return '$' . $amount;
                }

                function format_date($date)
                {
                    return $date;
                }

                function clean(string $value): string
                {
                    return trim($value);
                }
                PHP,
        ]);

        $violations = $this->analyse($basePath)->forRule('helper.must_have_return_type');

        $this->assertCount(2, $violations);
        $this->assertStringContainsString('App\Helper\format_price()', $violations[0]->message);
        $this->assertStringContainsString('App\Helper\format_date()', $violations[1]->message);
    }

    public function testPassesWhenAllFunctionsDeclareReturnTypes(): void
    {
        $basePath = $this->makeTempProject([
            'src/Helper/functions.php' => <<<'PHP'
                <?php

                namespace App\Helper;

                function format_price(int $amount): string
                {
                    return '$' . $amount;
                }

                function clean(string $value): string
                {
                    return trim($value);
                }
                PHP,
        ]);

        $violations = $this->analyse($basePath)->forRule('helper.must_have_return_type');

        $this->assertCount(0, $violations);
    }

    public function testMvcPresetFlagsUntypedHelperFunctions(): void
    {
        $basePath = $this->makeTempProject([
            'src/Helper/functions.php' => <<<'PHP'
                <?php

                namespace App\Helper;

                function format_price($amount)
                {
                    return '$' . $amount;
                }

                function format_date($date)
                {
                    return $date;
                }
                PHP,
        ]);

        $architecture = Architecture::define();
        Preset::MVC()->apply($architecture);

        $violations = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule(MvcPreset::HELPER_MUST_HAVE_RETURN_TYPES);

        $this->assertCount(2, $violations);
        $this->assertStringContainsString('App\Helper\format_price()', $violations[0]->message);
        $this->assertStringContainsString('App\Helper\format_date()', $violations[1]->message);
    }

    private function analyse(string $basePath): RuleViolationCollection
    {
        $architecture = Architecture::define()
            ->layer('Helper', 'src/Helper/')
            ->rule('helper.must_have_return_type', new MustHaveReturnTypeFunctionRule('Helper'));

        return (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential());
    }

    /** @param array<string, string> $files */
    private function makeTempProject(array $files): string
    {
        $basePath = $this->makeTemporaryDirectory('structarmed-must-have-return-type-function');

        foreach ($files as $file => $contents) {
            $path = $basePath . '/' . $file;

            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }

            file_put_contents($path, $contents);
        }

        return $basePath;
    }
}
