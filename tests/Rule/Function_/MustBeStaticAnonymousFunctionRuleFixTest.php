<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Function_;

use Boundwize\StructArmed\Analyser\Analyser;
use Boundwize\StructArmed\Analyser\AnalyserOptions;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\FunctionLike\AddStaticAnonymousFunctionVisitor;
use Boundwize\StructArmed\Rule\Rules\Function_\MustBeStaticAnonymousFunctionRule;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function mkdir;

#[CoversClass(MustBeStaticAnonymousFunctionRule::class)]
#[CoversClass(AddStaticAnonymousFunctionVisitor::class)]
final class MustBeStaticAnonymousFunctionRuleFixTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testAnalyseThenFixAddsStaticOnlyToFlaggedAnonymousFunctions(): void
    {
        $basePath = $this->makeTemporaryDirectory('structarmed-static-closure');
        mkdir($basePath . '/src');

        $file = $basePath . '/src/Handler.php';

        file_put_contents(
            $file,
            "<?php\n\n"
            . "declare(strict_types=1);\n\n"
            . "namespace App;\n\n"
            . "final class Handler\n"
            . "{\n"
            . "    public function handle(): array\n"
            . "    {\n"
            . "        return [\n"
            . "            function () { return 1; },\n"
            . "            fn () => \$this->value,\n"
            . "            static fn () => 2,\n"
            . "            fn (int \$x) => \$x * 2,\n"
            . "        ];\n"
            . "    }\n"
            . "}\n"
        );

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('source.static_closures', new MustBeStaticAnonymousFunctionRule(layer: 'Source'));

        $violations = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule('source.static_closures');

        $this->assertCount(2, $violations);
        $this->assertSame([12, 15], [$violations[0]->line, $violations[1]->line]);
        $this->assertTrue($violations[0]->fixable);

        $rule = $architecture->getRules()['source.static_closures'];
        $this->assertInstanceOf(MustBeStaticAnonymousFunctionRule::class, $rule);

        foreach ($violations as $violation) {
            $this->assertTrue($rule->fix($violation));
        }

        $this->assertSame(
            "<?php\n\n"
            . "declare(strict_types=1);\n\n"
            . "namespace App;\n\n"
            . "final class Handler\n"
            . "{\n"
            . "    public function handle(): array\n"
            . "    {\n"
            . "        return [\n"
            . "            static function () { return 1; },\n"
            . "            fn () => \$this->value,\n"
            . "            static fn () => 2,\n"
            . "            static fn (int \$x) => \$x * 2,\n"
            . "        ];\n"
            . "    }\n"
            . "}\n",
            file_get_contents($file)
        );

        // A second analysis of the fixed file is clean.
        $this->assertCount(
            0,
            (new Analyser($basePath))
                ->analyse($architecture, [], null, AnalyserOptions::sequential())
                ->forRule('source.static_closures')
        );
    }
}
