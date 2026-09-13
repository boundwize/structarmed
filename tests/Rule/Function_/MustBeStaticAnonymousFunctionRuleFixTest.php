<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Function_;

use Boundwize\StructArmed\Analyser\Analyser;
use Boundwize\StructArmed\Analyser\AnalyserOptions;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\FunctionLike\AddStaticAnonymousFunctionVisitor;
use Boundwize\StructArmed\Rule\Rules\Function_\MustBeStaticAnonymousFunctionRule;
use Boundwize\StructArmed\Rule\RuleViolation;
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

    public function testObjectBoundAnonymousFunctionsProduceNoViolationOrFix(): void
    {
        $basePath = $this->makeTemporaryDirectory('structarmed-object-bound-closure');
        mkdir($basePath . '/src');

        $file = $basePath . '/src/closures.php';
        $code = <<<'PHP'
<?php

\Closure::bind(function (): void { foo(); }, new stdClass());
\Closure::bind(newThis: new stdClass(), closure: function (): void { foo(); });
(function (): void { foo(); })->bindTo(new stdClass());
(function (): void { foo(); })->bindTo(newThis: new stdClass());
(function (): void { foo(); })->call(new stdClass());
(function (): void { foo(); })->call(newThis: new stdClass());
\Closure::bind(fn () => foo(), new stdClass());
\Closure::bind(newThis: new stdClass(), closure: fn () => foo());
(fn () => foo())->bindTo(new stdClass());
(fn () => foo())->bindTo(newThis: new stdClass());
(fn () => foo())->call(new stdClass());
(fn () => foo())->call(newThis: new stdClass());
(function (): void { foo(); })?->bindTo(new stdClass());
(function (): void { foo(); })?->call(new stdClass());
(fn () => foo())?->bindTo(new stdClass());
(fn () => foo())?->call(new stdClass());
PHP;
        file_put_contents($file, $code);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('source.static_closures', new MustBeStaticAnonymousFunctionRule(layer: 'Source'));

        $violations = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule('source.static_closures');

        $this->assertCount(0, $violations);

        $rule = $architecture->getRules()['source.static_closures'];
        $this->assertInstanceOf(MustBeStaticAnonymousFunctionRule::class, $rule);
        $this->assertFalse($rule->fix(new RuleViolation(
            message:   'Closure in [file scope] must be declared static',
            file:      $file,
            line:      3,
            className: 'file scope',
            layer:     'Source',
        )));
        $this->assertSame($code, file_get_contents($file));
    }

    public function testScopeOnlyBindingsRemainFixable(): void
    {
        $basePath = $this->makeTemporaryDirectory('structarmed-scope-bound-closure');
        mkdir($basePath . '/src');

        $file = $basePath . '/src/closures.php';
        file_put_contents(
            $file,
            <<<'PHP'
<?php

\Closure::bind(function (): void { foo(); }, null, Foo::class);
(function (): void { foo(); })->bindTo(null, Foo::class);
\Closure::bind(fn () => foo(), null, Foo::class);
(fn () => foo())->bindTo(null, Foo::class);
\Closure::bind(newThis: null, closure: function (): void { foo(); }, newScope: Foo::class);
(function (): void { foo(); })->bindTo(newThis: null, newScope: Foo::class);
\Closure::bind(newThis: null, closure: fn () => foo(), newScope: Foo::class);
(fn () => foo())->bindTo(newThis: null, newScope: Foo::class);
PHP
        );

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('source.static_closures', new MustBeStaticAnonymousFunctionRule(layer: 'Source'));

        $violations = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule('source.static_closures');

        $this->assertCount(8, $violations);

        $rule = $architecture->getRules()['source.static_closures'];
        $this->assertInstanceOf(MustBeStaticAnonymousFunctionRule::class, $rule);
        $this->assertTrue($rule->fix(...$violations));

        $this->assertSame(
            <<<'PHP'
<?php

\Closure::bind(static function (): void { foo(); }, null, Foo::class);
(static function (): void { foo(); })->bindTo(null, Foo::class);
\Closure::bind(static fn () => foo(), null, Foo::class);
(static fn () => foo())->bindTo(null, Foo::class);
\Closure::bind(newThis: null, closure: static function (): void { foo(); }, newScope: Foo::class);
(static function (): void { foo(); })->bindTo(newThis: null, newScope: Foo::class);
\Closure::bind(newThis: null, closure: static fn () => foo(), newScope: Foo::class);
(static fn () => foo())->bindTo(newThis: null, newScope: Foo::class);
PHP,
            file_get_contents($file)
        );
    }

    public function testFixDoesNotChangeOtherAnonymousFunctionOnSameLine(): void
    {
        $basePath = $this->makeTemporaryDirectory('structarmed-static-closure-line');
        mkdir($basePath . '/src');

        $file = $basePath . '/src/Handler.php';

        file_put_contents(
            $file,
            "<?php\n\n"
            . "namespace App;\n\n"
            . "final class Handler\n"
            . "{\n"
            . "    public function handle(): array\n"
            . "    {\n"
            . "        return [fn () => 1, fn () => \$this->value, function () { return 2; }];\n"
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
        $this->assertSame(9, $violations[0]->line);
        $this->assertSame(9, $violations[1]->line);

        $rule = $architecture->getRules()['source.static_closures'];
        $this->assertInstanceOf(MustBeStaticAnonymousFunctionRule::class, $rule);

        $this->assertTrue($rule->fix($violations[0]));

        $this->assertSame(
            "<?php\n\n"
            . "namespace App;\n\n"
            . "final class Handler\n"
            . "{\n"
            . "    public function handle(): array\n"
            . "    {\n"
            . "        return [static fn () => 1, fn () => \$this->value, static function () { return 2; }];\n"
            . "    }\n"
            . "}\n",
            file_get_contents($file)
        );
    }

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
