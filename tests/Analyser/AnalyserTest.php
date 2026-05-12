<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser;

use Boundwize\StructArmed\Analyser\Analyser;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Cache\AnalysisResultCache;
use Boundwize\StructArmed\Preset\Preset;
use Boundwize\StructArmed\Preset\Presets\Psr1Preset;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeFinalRule;
use Boundwize\StructArmed\Rule\Rules\Method\MaxMethodLengthRule;
use Boundwize\StructArmed\Rule\Rules\Usage\MayNotUseClassRule;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function str_replace;
use function symlink;

#[CoversClass(Analyser::class)]
final class AnalyserTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testAnalyserReturnsNoViolationsForValidCode(): void
    {
        $architecture = Architecture::define()
            ->layer('Domain', 'tests/Fixtures/sample/src/Domain/')
            ->layer('Application', 'tests/Fixtures/sample/src/Application/')
            ->layer('Infrastructure', 'tests/Fixtures/sample/src/Infrastructure/');

        $analyser                = new Analyser(dirname(__DIR__, 2));
        $ruleViolationCollection = $analyser->analyse($architecture);

        // Order.php is a valid entity — should produce no layer violations
        $this->assertEmpty($ruleViolationCollection->forLayer('Application'));
        $this->assertEmpty($ruleViolationCollection->forLayer('Infrastructure'));
    }

    public function testAnalyserDetectsViolationsInBadCode(): void
    {
        $architecture = Architecture::define()
            ->layer('Domain', 'tests/Fixtures/sample/src/Domain/')
            ->layer('Application', 'tests/Fixtures/sample/src/Application/')
            ->layer('Infrastructure', 'tests/Fixtures/sample/src/Infrastructure/')
            ->withPreset(Preset::DDD());

        $analyser                = new Analyser(dirname(__DIR__, 2));
        $ruleViolationCollection = $analyser->analyse($architecture);

        // BadOrderEntity.php uses DateTime and is not final — should have violations
        $this->assertTrue($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserReturnsEmptyCollectionForEmptyLayers(): void
    {
        $architecture = Architecture::define()
            ->layer('Domain', 'tests/Fixtures/sample/src/Domain/Events/');

        $analyser                = new Analyser(dirname(__DIR__, 2));
        $ruleViolationCollection = $analyser->analyse($architecture);

        // Events directory is empty — no violations
        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserSkipsNonExistentPaths(): void
    {
        $architecture = Architecture::define()
            ->layer('Domain', 'tests/Fixtures/sample/src/DoesNotExist/');

        $analyser = new Analyser(dirname(__DIR__, 2));

        // Should not throw — simply skip missing directories
        $ruleViolationCollection = $analyser->analyse($architecture);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserSkipsFilesWithParseErrors(): void
    {
        $basePath = $this->makeTempProject([
            'src/Domain/Broken.php' => '<?php class Broken {',
        ]);

        $architecture = Architecture::define()
            ->layer('Domain', 'src/Domain/');

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserSkipsFilesWithEmptyAst(): void
    {
        $basePath = $this->makeTempProject([
            'src/Domain/Empty.php' => '<?php',
        ]);

        $architecture = Architecture::define()
            ->layer('Domain', 'src/Domain/');

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserEvaluatesProjectRules(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
            'src/Foo.php'   => '<?php namespace App; final class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::PSR4(sourcePaths: ['src/', 'tests/']));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertTrue($ruleViolationCollection->hasViolations());
        $this->assertCount(1, $ruleViolationCollection->forRule('psr4.source_paths.must_be_in_composer'));
    }

    public function testAnalyserSkipPathOnProjectRuleSuppressesViolations(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json'     => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
            'src/Foo.php'       => "<?php\nini_set('memory_limit', '1G');\nclass Foo {}\n",
            'tests/FooTest.php' => "<?php\nini_set('memory_limit', '1G');\nclass FooTest {}\n",
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::PSR1(sourcePaths: ['src/', 'tests/']))
            ->skip([
                Psr1Preset::FILES_SHOULD_DECLARE_SYMBOLS_OR_SIDE_EFFECTS => [$basePath . '/tests'],
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $violations = $ruleViolationCollection->forRule(Psr1Preset::FILES_SHOULD_DECLARE_SYMBOLS_OR_SIDE_EFFECTS);
        $this->assertCount(1, $violations);
        $this->assertStringEndsWith('/src/Foo.php', $this->normalisePath($violations[0]->file));
    }

    public function testAnalyserContinuesWhenProjectRulePasses(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
            'src/Foo.php'   => '<?php namespace App; final class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::PSR4(sourcePaths: ['src/']));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserUsesComposerPsr4PathsForDefaultPsr4Preset(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"app/"}}}',
            'app/Foo.php'   => '<?php namespace App; class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::PSR4())
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertCount(1, $ruleViolationCollection->forRule('source.must_be_final'));
    }

    public function testDddPresetResolvesConventionalLayersInsidePsr4SourceLayer(): void
    {
        $repositoryPath = 'src/Infrastructure/Persistence/Album/SQLAlbumRepository.php';
        $basePath       = $this->makeTempProject([
            'composer.json' => '{"autoload":{"psr-4":{"Album\\\\":"src/"}}}',
            $repositoryPath => <<<'PHP'
                <?php

                namespace Album\Infrastructure\Persistence\Album;

                final class SQLAlbumRepository
                {
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->withPresets(Preset::PSR4(), Preset::DDD());

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertCount(
            0,
            $ruleViolationCollection->forRule('ddd.repository.implementation_in_infrastructure')
        );
    }

    public function testAnalyserCanLimitScanToSpecificFile(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php' => '<?php namespace App; class Foo {}',
            'src/Bar.php' => '<?php namespace App; class Bar {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', ['src/'])
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/Foo.php']);

        $this->assertCount(1, $ruleViolationCollection->forRule('source.must_be_final'));
        $this->assertStringEndsWith(
            '/src/Foo.php',
            $this->normalisePath($ruleViolationCollection->forRule('source.must_be_final')[0]->file)
        );
    }

    public function testAnalyserCanLimitScanToSpecificPaths(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php'   => '<?php namespace App; class Foo {}',
            'tests/Bar.php' => '<?php namespace App\Tests; class Bar {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', ['src/', 'tests/'])
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertCount(1, $ruleViolationCollection->forRule('source.must_be_final'));
        $this->assertStringEndsWith(
            '/src/Foo.php',
            $this->normalisePath($ruleViolationCollection->forRule('source.must_be_final')[0]->file)
        );
    }

    public function testAnalyserDoesNotScanDuplicateLayerPathsTwice(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php' => '<?php namespace App; class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', ['src/', 'src/'])
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertCount(1, $ruleViolationCollection->forRule('source.must_be_final'));
    }

    public function testAnalyserReportsProgressForScannedPhpFiles(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php'   => '<?php namespace App; final class Foo {}',
            'src/Bar.php'   => '<?php namespace App; final class Bar {}',
            'src/readme.md' => '# ignored',
        ]);
        $progress = new class implements ProgressHandlerInterface {
            public int $total = 0;

            /** @var list<string> */
            public array $files = [];

            public bool $finished = false;

            public function start(int $total): void
            {
                $this->total = $total;
            }

            public function advance(string $file): void
            {
                $this->files[] = $file;
            }

            public function finish(): void
            {
                $this->finished = true;
            }
        };

        $architecture = Architecture::define()
            ->layer('Source', 'src/');

        (new Analyser($basePath))->analyse($architecture, [], $progress);

        $this->assertSame(2, $progress->total);
        $this->assertCount(2, $progress->files);
        $this->assertTrue($progress->finished);
    }

    public function testAnalyserReportsProgressOnlyForFilesMissingFromClassNodeCache(): void
    {
        $basePath            = $this->makeTempProject([
            'src/Foo.php' => '<?php namespace App; final class Foo {}',
            'src/Bar.php' => '<?php namespace App; final class Bar {}',
        ]);
        $analysisResultCache = new AnalysisResultCache($basePath, 'cache');
        $progress            = new class implements ProgressHandlerInterface {
            public int $total = 0;

            /** @var list<string> */
            public array $files = [];

            public function start(int $total): void
            {
                $this->total = $total;
            }

            public function advance(string $file): void
            {
                $this->files[] = $file;
            }

            public function finish(): void
            {
            }
        };

        $architecture = Architecture::define()
            ->layer('Source', 'src/');

        (new Analyser($basePath, $analysisResultCache, 'config'))->analyse($architecture);
        file_put_contents($basePath . '/src/Baz.php', '<?php namespace App; final class Baz {}');

        (new Analyser($basePath, $analysisResultCache, 'config'))->analyse($architecture, [], $progress);

        $this->assertSame(1, $progress->total);
        $this->assertCount(1, $progress->files);
        $this->assertStringEndsWith('/src/Baz.php', $this->normalisePath($progress->files[0]));
    }

    public function testAnalyserReportsAllViolationsFromMultipleViolationRules(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php' => <<<'PHP'
                <?php

                namespace App;

                class Foo
                {
                    public function first(): void
                    {
                        $a = 1;
                        $b = 2;
                    }

                    public function second(): void
                    {
                        $a = 1;
                        $b = 2;
                        $c = 3;
                    }
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('source.max_method_length', new MaxMethodLengthRule('Source', 2));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $violations = $ruleViolationCollection->forRule('source.max_method_length');

        $this->assertCount(2, $violations);
        $this->assertStringContainsString('first', $violations[0]->message);
        $this->assertStringContainsString('second', $violations[1]->message);
    }

    public function testAnalyserTreatsAliasedImportAsDependency(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php' => <<<'PHP'
                <?php

                namespace App;

                use Vendor\ForbiddenService as Service;

                final class Foo
                {
                    public function __construct(private Service $service)
                    {
                    }

                    public function run(): void
                    {
                    }
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule(
                'source.must_not_use_forbidden_service',
                new MayNotUseClassRule('Source', 'Vendor\ForbiddenService')
            );

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertCount(
            1,
            $ruleViolationCollection->forRule('source.must_not_use_forbidden_service')
        );
    }

    public function testAnalyserTreatsGroupedAliasedImportAsDependency(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php' => <<<'PHP'
                <?php

                namespace App;

                use FooLibrary\Bar\Baz\{ClassA, ClassB, ClassC, ClassD as Fizbo};

                final class Foo
                {
                    public function __construct(private Fizbo $service)
                    {
                    }
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule(
                'source.must_not_use_grouped_alias',
                new MayNotUseClassRule('Source', 'FooLibrary\Bar\Baz\ClassD')
            );

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertCount(
            1,
            $ruleViolationCollection->forRule('source.must_not_use_grouped_alias')
        );
    }

    public function testDefaultPsr4PresetDetectsClassesThatDoNotMatchScannedComposerPath(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json' => '{"autoload-dev":{"psr-4":{"App\\\\Tests\\\\":"tests/"}}}',
            'tests/Foo.php' => '<?php class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::PSR4());

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['tests/']);

        $this->assertCount(1, $ruleViolationCollection->forRule('psr4.classes.must_match_composer'));
        $this->assertStringContainsString(
            'App\\Tests\\Foo',
            $ruleViolationCollection->forRule('psr4.classes.must_match_composer')[0]->message
        );
    }

    public function testAnalyserSkipsConfiguredPathsInsideExplicitScanPath(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php'              => '<?php namespace App; class Foo {}',
            'src/Fixtures/Ignored.php' => '<?php namespace App\Fixtures; class Ignored {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->skip(['src/Fixtures/'])
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertCount(1, $ruleViolationCollection->forRule('source.must_be_final'));
        $this->assertStringEndsWith(
            '/src/Foo.php',
            $this->normalisePath($ruleViolationCollection->forRule('source.must_be_final')[0]->file)
        );
    }

    public function testAnalyserSkipsEntireConfiguredScanPath(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php' => '<?php namespace App; class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->skip(['src/'])
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserCanCompareBasePathWhenCheckingSkips(): void
    {
        $basePath = $this->makeTempProject([
            'Foo.php' => '<?php class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', '.')
            ->skip(['does-not-match'])
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertCount(1, $ruleViolationCollection->forRule('source.must_be_final'));
    }

    public function testAnalyserSkipsConfiguredGlobPaths(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php'                   => '<?php namespace App; class Foo {}',
            'src/Generated/Ignored.php'     => '<?php namespace App\Generated; class Ignored {}',
            'src/Generated/Nested/Nope.php' => '<?php namespace App\Generated\Nested; class Nope {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->skip(['src/Generated/*'])
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertCount(1, $ruleViolationCollection->forRule('source.must_be_final'));
        $this->assertStringEndsWith(
            '/src/Foo.php',
            $this->normalisePath($ruleViolationCollection->forRule('source.must_be_final')[0]->file)
        );
    }

    public function testAnalyserKeepsFilesWhenGlobSkipDoesNotMatch(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php' => '<?php namespace App; class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->skip(['tests/*'])
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertCount(1, $ruleViolationCollection->forRule('source.must_be_final'));
    }

    public function testAnalyserSkipsConfiguredPathsForSpecificRuleOnly(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php'        => '<?php namespace App; class Foo {}',
            'src/Legacy/Old.php' => '<?php namespace App\Legacy; class Old {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->skip(['source.must_be_final' => ['src/Legacy/']])
            ->rule('source.must_be_final', new MustBeFinalRule('Source'))
            ->rule('source.must_be_final_too', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertCount(1, $ruleViolationCollection->forRule('source.must_be_final'));
        $this->assertCount(2, $ruleViolationCollection->forRule('source.must_be_final_too'));
        $this->assertStringEndsWith(
            '/src/Foo.php',
            $this->normalisePath($ruleViolationCollection->forRule('source.must_be_final')[0]->file)
        );
    }

    public function testAnalyserSkipsRuleConfiguredBeforePresetRegistersIt(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
            'src/Foo.php'   => '<?php namespace App; class Foo { public function Bad_method(): void {} }',
        ]);

        $architecture = Architecture::define()
            ->skip([
                'tests/Fixtures/',
                Psr1Preset::METHODS_MUST_BE_CAMEL_CASE,
            ])
            ->withPreset(Preset::PSR1(sourcePaths: ['src/']));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertCount(0, $ruleViolationCollection->forRule(Psr1Preset::METHODS_MUST_BE_CAMEL_CASE));
    }

    public function testAnalyserCanCheckRuleSkipsForRealPathOutsideBasePath(): void
    {
        $basePath    = $this->makeTempProject([
            'src/.keep' => '',
        ]);
        $outsidePath = $this->makeTemporaryFile('structarmed-outside');
        file_put_contents($outsidePath, '<?php class Linked {}');
        symlink($outsidePath, $basePath . '/src/Linked.php');

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->skip(['source.must_be_final' => ['does-not-match']])
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    /** @param array<string, string> $files */
    private function makeTempProject(array $files): string
    {
        $basePath = $this->makeTemporaryDirectory('structarmed-analyser');

        foreach ($files as $file => $contents) {
            $path = $basePath . '/' . $file;

            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }

            file_put_contents($path, $contents);
        }

        return $basePath;
    }

    private function normalisePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
