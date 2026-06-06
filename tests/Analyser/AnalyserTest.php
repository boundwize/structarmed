<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser;

use Boundwize\StructArmed\Analyser\Analyser;
use Boundwize\StructArmed\Analyser\AnalyserOptions;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Cache\AnalysisResultCache;
use Boundwize\StructArmed\Preset\Preset;
use Boundwize\StructArmed\Preset\Presets\Psr1Preset;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeFinalRule;
use Boundwize\StructArmed\Rule\Rules\Layer\MayNotDependOnRule;
use Boundwize\StructArmed\Rule\Rules\Method\MaxMethodLengthRule;
use Boundwize\StructArmed\Rule\Rules\Usage\MayNotUseClassRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function count;
use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function sort;
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

    public function testAnalyserCollectsClassNodesWithSequentialRunner(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php' => '<?php namespace App; class Foo {}',
            'src/Bar.php' => '<?php namespace App; class Bar {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential());

        $this->assertCount(2, $ruleViolationCollection->forRule('source.must_be_final'));
    }

    public function testAnalyserCollectsClassNodesWithSequentialRunnerAndLayerPatterns(): void
    {
        $basePath = $this->makeTempProject([
            'src/HTTP/Request.php' => '<?php namespace App\HTTP; class Request {}',
        ]);

        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->rule('http.must_be_final', new MustBeFinalRule('HTTP'));

        $ruleViolationCollection = (new Analyser($basePath))
            ->analyse($architecture, ['src/'], null, AnalyserOptions::sequential());

        $this->assertCount(1, $ruleViolationCollection->forRule('http.must_be_final'));
    }

    public function testAnalyserCollectsClassNodesWithDefaultParallelRunner(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php' => '<?php namespace App; class Foo {}',
            'src/Bar.php' => '<?php namespace App; class Bar {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertCount(2, $ruleViolationCollection->forRule('source.must_be_final'));
    }

    public function testArchitectureLayerPathDoesNotMatchSiblingDirectoryWithSamePrefix(): void
    {
        $basePath = $this->makeTempProject([
            'src/App/Controller.php'      => '<?php namespace Project\App; class Controller {}',
            'src/Application/UseCase.php' => '<?php namespace Project\Application; class UseCase {}',
        ]);

        $architecture = Architecture::define()
            ->layer('App', 'src/App/')
            ->layer('Application', 'src/Application/')
            ->rule('app.must_be_final', new MustBeFinalRule('App'));

        $ruleViolationCollection = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential());

        $violations = $ruleViolationCollection->forRule('app.must_be_final');

        $this->assertCount(1, $violations);
        $this->assertStringEndsWith('/src/App/Controller.php', $this->normalisePath($violations[0]->file));
    }

    public function testArchitectureLayerCanBeConfiguredForSingleFilePath(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php' => '<?php namespace App; class Foo {}',
            'src/Bar.php' => '<?php namespace App; class Bar {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/Foo.php')
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential());

        $violations = $ruleViolationCollection->forRule('source.must_be_final');

        $this->assertCount(1, $violations);
        $this->assertStringEndsWith('/src/Foo.php', $this->normalisePath($violations[0]->file));
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

    public function testAnalyserUsesComposerPsr4PathsForDefaultPsr15Preset(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"app/"}}}',
            'app/Foo.php'   => '<?php namespace App; class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::PSR15())
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

    public function testFilesForAnalysisIgnoresDirectorySymlinks(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php'      => '<?php namespace App; final class Foo {}',
            'src/Docs/read.md' => '# ignored',
        ]);
        mkdir($basePath . '/LinkedDirectory');
        symlink($basePath . '/LinkedDirectory', $basePath . '/src/LinkedDirectory');

        $architecture = Architecture::define()
            ->layer('Source', 'src/');

        $files = (new Analyser($basePath))->filesForAnalysis($architecture);

        $this->assertCount(1, $files);
        $this->assertStringEndsWith('/src/Foo.php', $this->normalisePath($files[0]));
    }

    public function testFilesForAnalysisWithAbsoluteScanPath(): void
    {
        $basePath = $this->makeTempProject([
            'index.php'   => '<?php namespace App; final class Index {}',
            'src/Foo.php' => '<?php namespace App; final class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/');

        $absoluteScanPath = $basePath . '/index.php';

        $files = (new Analyser($basePath))->filesForAnalysis($architecture, [$absoluteScanPath]);

        $this->assertCount(1, $files);
        $this->assertStringEndsWith('/index.php', $this->normalisePath($files[0]));
    }

    public function testFilesForAnalysisWithRootRelativeScanPath(): void
    {
        $basePath = $this->makeTempProject([
            'index.php'   => '<?php namespace App; final class Index {}',
            'src/Foo.php' => '<?php namespace App; final class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/');

        $files = (new Analyser($basePath))->filesForAnalysis($architecture, ['index.php']);

        $this->assertCount(1, $files);
        $this->assertStringEndsWith('/index.php', $this->normalisePath($files[0]));
    }

    public function testFilesForAnalysisUsesPreResolvedLayersWhenProvided(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php' => '<?php namespace App; final class Foo {}',
            'src/Bar.php' => '<?php namespace App; final class Bar {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/');

        $analyser       = new Analyser($basePath);
        $resolvedLayers = ['Source' => ['src/']];

        $filesWithLayers    = $analyser->filesForAnalysis($architecture, [], $resolvedLayers);
        $filesWithoutLayers = $analyser->filesForAnalysis($architecture);

        $this->assertCount(2, $filesWithoutLayers);
        $this->assertSame($filesWithoutLayers, $filesWithLayers);
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
            ->rule('source.max_method_length', new MaxMethodLengthRule('Source', 1));

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

    public function testAnalyserSkipsAbsoluteSkipPath(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php'              => '<?php namespace App; class Foo {}',
            'src/Fixtures/Ignored.php' => '<?php namespace App\Fixtures; class Ignored {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->skip([$basePath . '/src/Fixtures'])
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertCount(1, $ruleViolationCollection->forRule('source.must_be_final'));
        $this->assertStringEndsWith(
            '/src/Foo.php',
            $this->normalisePath($ruleViolationCollection->forRule('source.must_be_final')[0]->file)
        );
    }

    public function testAnalyserSkipsAbsoluteSkipPathWithDotDotSegment(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php'              => '<?php namespace App; class Foo {}',
            'src/Fixtures/Ignored.php' => '<?php namespace App\Fixtures; class Ignored {}',
        ]);

        // A `..` segment that the old fallback str_starts_with could not resolve —
        // only a properly normalised (realpath-resolved) absolute skip path will match.
        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->skip([$basePath . '/src/../src/Fixtures'])
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertCount(1, $ruleViolationCollection->forRule('source.must_be_final'));
        $this->assertStringEndsWith(
            '/src/Foo.php',
            $this->normalisePath($ruleViolationCollection->forRule('source.must_be_final')[0]->file)
        );
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

    public function testAnalyserEvaluatesRulesetAndDetectsLayerViolation(): void
    {
        $basePath = $this->makeTempProject([
            'src/HTTP/Request.php' => <<<'PHP'
                <?php

                namespace App\HTTP;

                use App\Database\QueryBuilder;

                final class Request
                {
                    public function __construct(private QueryBuilder $db) {}
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->layerPattern('Database', '/^App\\\\Database\\\\.*$/')
            ->ruleset([
                'HTTP' => ['Cookie', 'Files', 'I18n'], // Database NOT allowed
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertTrue($ruleViolationCollection->hasViolations());
        $violations = $ruleViolationCollection->forRule('ruleset.HTTP');
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('Database', $violations[0]->message);
    }

    public function testAnalyserEvaluatesRulesetAndDetectsLayerViolationWithPathBasedLayers(): void
    {
        $basePath = $this->makeTempProject([
            'src/HTTP/Request.php'          => <<<'PHP'
                <?php

                namespace App\HTTP;

                use App\Database\QueryBuilder;

                final class Request
                {
                    public function __construct(private QueryBuilder $db) {}
                }
                PHP,
            'src/Database/QueryBuilder.php' => <<<'PHP'
                <?php

                namespace App\Database;

                final class QueryBuilder {}
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('HTTP', 'src/HTTP/')
            ->layer('Database', 'src/Database/')
            ->ruleset([
                'HTTP' => [], // Database NOT allowed
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertTrue($ruleViolationCollection->hasViolations());
        $violations = $ruleViolationCollection->forRule('ruleset.HTTP');
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('Database', $violations[0]->message);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function importedSymbolDependencyProvider(): iterable
    {
        yield 'constant fetch' => [
            <<<'PHP'
                <?php

                namespace App\HTTP;

                use const App\Database\Config\DEFAULT_TIMEOUT;

                final class Request
                {
                    public function timeout(): int
                    {
                        return DEFAULT_TIMEOUT;
                    }
                }
                PHP,
            ['App\Database\Config\DEFAULT_TIMEOUT'],
        ];

        yield 'function call' => [
            <<<'PHP'
                <?php

                namespace App\HTTP;

                use function App\Database\Support\query;

                final class Request
                {
                    public function run(): void
                    {
                        query();
                    }
                }
                PHP,
            ['App\Database\Support\query'],
        ];

        yield 'grouped constant fetch' => [
            <<<'PHP'
                <?php

                namespace App\HTTP;

                use const App\Database\Config\{DEFAULT_TIMEOUT, RETRY_LIMIT};

                final class Request
                {
                    public function timeout(): int
                    {
                        return DEFAULT_TIMEOUT + RETRY_LIMIT;
                    }
                }
                PHP,
            [
                'App\Database\Config\DEFAULT_TIMEOUT',
                'App\Database\Config\RETRY_LIMIT',
            ],
        ];

        yield 'grouped function call' => [
            <<<'PHP'
                <?php

                namespace App\HTTP;

                use function App\Database\Support\{query, trace};

                final class Request
                {
                    public function run(): void
                    {
                        query();
                        trace();
                    }
                }
                PHP,
            [
                'App\Database\Support\query',
                'App\Database\Support\trace',
            ],
        ];
    }

    /**
     * @param list<string> $dependencies
     */
    #[DataProvider('importedSymbolDependencyProvider')]
    public function testAnalyserRulesetTreatsImportedConstantsAndFunctionsAsDependencies(
        string $sourceCode,
        array $dependencies
    ): void {
        $basePath = $this->makeTempProject([
            'src/HTTP/Request.php' => $sourceCode,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->layerPattern('Database', '/^App\\\\Database\\\\.*$/')
            ->ruleset([
                'HTTP' => [],
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $violations = $ruleViolationCollection->forRule('ruleset.HTTP');

        $this->assertCount(count($dependencies), $violations);

        foreach ($dependencies as $index => $dependency) {
            $this->assertStringContainsString($dependency, $violations[$index]->message);
            $this->assertStringContainsString('Database', $violations[$index]->message);
        }
    }

    public function testAnalyserRulesetAllowsListedLayerDependency(): void
    {
        $basePath = $this->makeTempProject([
            'src/HTTP/Request.php' => <<<'PHP'
                <?php

                namespace App\HTTP;

                use App\Cookie\CookieJar;

                final class Request
                {
                    public function __construct(private CookieJar $cookies) {}
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->layerPattern('Cookie', '/^App\\\\Cookie\\\\.*$/')
            ->ruleset([
                'HTTP' => ['Cookie'], // Cookie IS allowed
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserRulesetIgnoresExternalDependencies(): void
    {
        $basePath = $this->makeTempProject([
            'src/HTTP/Request.php' => <<<'PHP'
                <?php

                namespace App\HTTP;

                use Psr\Http\Message\RequestInterface;

                final class Request implements RequestInterface
                {
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->ruleset([
                'HTTP' => [], // nothing allowed, but external deps are fine
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserRulesetSkipClassViolationSuppressesViolation(): void
    {
        $basePath = $this->makeTempProject([
            'src/HTTP/Request.php' => <<<'PHP'
                <?php

                namespace App\HTTP;

                use App\Database\QueryBuilder;

                final class Request
                {
                    public function __construct(private QueryBuilder $db) {}
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->layerPattern('Database', '/^App\\\\Database\\\\.*$/')
            ->ruleset(['HTTP' => []])
            ->skipClassViolation('App\\HTTP\\Request', 'App\\Database\\QueryBuilder');

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserRulesetSkipClassViolationSuppressesViolationWithPathBasedLayers(): void
    {
        $basePath = $this->makeTempProject([
            'src/HTTP/Request.php'          => <<<'PHP'
                <?php

                namespace App\HTTP;

                use App\Database\QueryBuilder;

                final class Request
                {
                    public function __construct(private QueryBuilder $db) {}
                }
                PHP,
            'src/Database/QueryBuilder.php' => <<<'PHP'
                <?php

                namespace App\Database;

                final class QueryBuilder {}
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('HTTP', 'src/HTTP/')
            ->layer('Database', 'src/Database/')
            ->ruleset(['HTTP' => []])
            ->skipClassViolation('App\\HTTP\\Request', 'App\\Database\\QueryBuilder');

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertCount(0, $ruleViolationCollection->forRule('ruleset.HTTP'));
    }

    public function testAnalyserRulesetReportsTransitiveSameLayerDependencyViolations(): void
    {
        $basePath = $this->makeTempProject([
            'src/HTTP/ResponseTrait.php'    => <<<'PHP'
                <?php

                namespace App\HTTP;

                use App\Pager\PagerInterface;

                trait ResponseTrait
                {
                    public function setLink(PagerInterface $pager): void {}
                }
                PHP,
            'src/HTTP/Response.php'         => <<<'PHP'
                <?php

                namespace App\HTTP;

                final class Response
                {
                    use ResponseTrait;
                }
                PHP,
            'src/HTTP/DownloadResponse.php' => <<<'PHP'
                <?php

                namespace App\HTTP;

                final class DownloadResponse extends Response
                {
                }
                PHP,
            'src/Pager/PagerInterface.php'  => <<<'PHP'
                <?php

                namespace App\Pager;

                interface PagerInterface
                {
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->layerPattern('Pager', '/^App\\\\Pager\\\\.*$/')
            ->ruleset(['HTTP' => []])
            ->skipClassViolation('App\\HTTP\\DownloadResponse', 'App\\Pager\\PagerInterface');

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $violations = $ruleViolationCollection->forRule('ruleset.HTTP');
        $classes    = array_map(
            static fn(RuleViolation $ruleViolation): string => $ruleViolation->className,
            $violations
        );
        sort($classes);

        $this->assertCount(2, $violations);
        $this->assertSame(['App\\HTTP\\Response', 'App\\HTTP\\ResponseTrait'], $classes);
    }

    public function testAnalyserRulesetStopsResolvingCyclicInheritanceDependencies(): void
    {
        $basePath = $this->makeTempProject([
            'src/HTTP/First.php'            => <<<'PHP'
                <?php

                namespace App\HTTP;

                final class First extends Second
                {
                }
                PHP,
            'src/HTTP/Second.php'           => <<<'PHP'
                <?php

                namespace App\HTTP;

                use App\Database\QueryBuilder;

                final class Second extends First
                {
                    public function __construct(private QueryBuilder $db) {}
                }
                PHP,
            'src/Database/QueryBuilder.php' => <<<'PHP'
                <?php

                namespace App\Database;

                final class QueryBuilder
                {
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->layerPattern('Database', '/^App\\\\Database\\\\.*$/')
            ->ruleset(['HTTP' => []]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $violations = $ruleViolationCollection->forRule('ruleset.HTTP');
        $classes    = array_map(
            static fn(RuleViolation $ruleViolation): string => $ruleViolation->className,
            $violations
        );
        sort($classes);

        $this->assertSame(['App\\HTTP\\First', 'App\\HTTP\\Second'], $classes);
    }

    public function testAnalyserRulesetAllowsSameLayerDependencies(): void
    {
        $basePath = $this->makeTempProject([
            'src/HTTP/Request.php' => <<<'PHP'
                <?php

                namespace App\HTTP;

                use App\HTTP\Headers;

                final class Request
                {
                    public function __construct(private Headers $headers) {}
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->ruleset(['HTTP' => []]); // nothing allowed except same-layer

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserRulesetAllowsSameLayerDependenciesWhenLayerOverlapsWithCatchAll(): void
    {
        // When a parent layer (System → src/System/) overlaps with a specific sub-layer
        // (Files → src/System/Files/), a dependency within the same specific layer is
        // resolved to both layers. It must NOT be reported as a violation just because
        // the parent layer is not listed in the allowed layers.
        $basePath = $this->makeTempProject([
            'src/System/Files/File.php'     => <<<'PHP'
                <?php

                namespace App\System\Files;

                final class File {}
                PHP,
            'src/System/Files/FileInfo.php' => <<<'PHP'
                <?php

                namespace App\System\Files;

                use App\System\Files\File;

                final class FileInfo
                {
                    public function __construct(private File $file) {}
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('System', 'src/System/')
            ->layer('Files', 'src/System/Files/')
            ->ruleset(['Files' => []]); // nothing allowed except same-layer

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserRulesetAllowsDependencyWhenAnyOfItsLayersIsAllowed(): void
    {
        // A dependency resolved to two layers [Formatter, System] because Formatter
        // is a specific sub-path layer inside the System parent layer.
        // Entity lists Formatter as allowed. Even though System is not listed,
        // the dependency must NOT be reported as a violation because at least one
        // of the dependency's layers (Formatter) is explicitly allowed.
        $basePath = $this->makeTempProject([
            'src/System/Entity/User.php'             => <<<'PHP'
                <?php

                namespace App\System\Entity;

                use App\System\Formatter\DateFormatter;

                final class User
                {
                    public function __construct(private DateFormatter $formatter) {}
                }
                PHP,
            'src/System/Formatter/DateFormatter.php' => <<<'PHP'
                <?php

                namespace App\System\Formatter;

                final class DateFormatter {}
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('System', 'src/System/')
            ->layer('Entity', 'src/System/Entity/')
            ->layer('Formatter', 'src/System/Formatter/')
            ->ruleset([
                'Entity' => ['Formatter'], // Formatter allowed, System parent is NOT listed
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserRulesetAllowsDependencyOnUnclassifiedClassInSharedCatchAllLayer(): void
    {
        // Router is a sub-layer of System. RouterException depends on RuntimeException,
        // which lives in src/System/Exceptions/ but has no specific sub-layer — its
        // primary layer is System. Because Router is itself within System, RuntimeException
        // is in the same parent-layer universe and must NOT be flagged as a violation.
        $basePath = $this->makeTempProject([
            'src/System/Router/Exceptions/RouterException.php' => <<<'PHP'
                <?php

                namespace App\System\Router\Exceptions;

                use App\System\Exceptions\RuntimeException;

                final class RouterException extends RuntimeException {}
                PHP,
            'src/System/Exceptions/RuntimeException.php'       => <<<'PHP'
                <?php

                namespace App\System\Exceptions;

                class RuntimeException extends \RuntimeException {}
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('System', 'src/System/')
            ->layer('Router', 'src/System/Router/')
            ->ruleset([
                'Router' => [],
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserRulesetSkipsClassNodeWithNullLayer(): void
    {
        // A PHP file is scanned but the class inside it does not match any
        // layerPattern, so its ClassNode has layer=null. The ruleset evaluator
        // must skip such nodes without producing a violation.
        $basePath = $this->makeTempProject([
            'src/HTTP/Request.php'    => <<<'PHP'
                <?php

                namespace App\HTTP;

                final class Request {}
                PHP,
            'src/External/Logger.php' => <<<'PHP'
                <?php

                namespace App\External;

                use App\HTTP\Request;

                final class Logger
                {
                    public function __construct(private Request $request) {}
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            // Note: App\External\Logger does NOT match any layerPattern → layer=null
            ->ruleset([
                'HTTP' => [],
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        // App\External\Logger has layer=null and must be silently skipped
        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserRulesetDoesNotRestrictLayerAbsentFromRulesetKeys(): void
    {
        // A class belongs to a layer that IS defined via layerPattern, but that
        // layer is not listed as a key in the ruleset. The ruleset evaluator
        // must leave it unrestricted (allowedLayers=null → continue).
        $basePath = $this->makeTempProject([
            'src/Database/QueryBuilder.php' => <<<'PHP'
                <?php

                namespace App\Database;

                use App\HTTP\Request;

                final class QueryBuilder
                {
                    public function __construct(private Request $request) {}
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->layerPattern('Database', '/^App\\\\Database\\\\.*$/')
            ->ruleset([
                'HTTP' => ['Cookie'], // Database is NOT a ruleset key → unrestricted
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        // App\Database\QueryBuilder is in the Database layer which has no ruleset
        // entry, so the dependency on App\HTTP\Request must not produce a violation.
        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserRulesetSkipPathsForRulesetSuppressesViolationsForMatchingFiles(): void
    {
        // Files under tests/ cross layer boundaries intentionally.
        // skipPathsForRuleset() should suppress their ruleset violations
        // while still allowing production code to be checked.
        $basePath = $this->makeTempProject([
            'src/HTTP/Request.php'          => <<<'PHP'
                <?php

                namespace App\HTTP;

                final class Request {}
                PHP,
            'src/Database/QueryBuilder.php' => <<<'PHP'
                <?php

                namespace App\Database;

                use App\HTTP\Request;

                final class QueryBuilder
                {
                    public function __construct(private Request $request) {}
                }
                PHP,
            'tests/HTTP/RequestTest.php'    => <<<'PHP'
                <?php

                namespace App\HTTP;

                use App\Database\QueryBuilder;

                final class RequestTest
                {
                    public function __construct(private QueryBuilder $db) {}
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->layerPattern('Database', '/^App\\\\Database\\\\.*$/')
            ->ruleset([
                'HTTP'     => [], // Database NOT allowed
                'Database' => [], // HTTP NOT allowed
            ])
            ->skipPathsForRuleset(['*tests*']);

        // Production violation (src/Database/QueryBuilder.php → HTTP layer) must still fire.
        // Test violation (tests/HTTP/RequestTest.php → Database layer) must be suppressed.
        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/', 'tests/']);

        $this->assertTrue($ruleViolationCollection->hasViolations());
        $violations = $ruleViolationCollection->forRule('ruleset.Database');
        $this->assertCount(1, $violations);
        // The violation is from the production class, not the test class.
        $this->assertStringContainsString('App\\Database\\QueryBuilder', $violations[0]->message);
        // The test file violation must be absent.
        $this->assertCount(0, $ruleViolationCollection->forRule('ruleset.HTTP'));
    }

    public function testAnalyserRulesetSkipPathsForRulesetSuppressesViolationsForMatchingFilesWithPathBasedLayers(
    ): void
    {
        $basePath = $this->makeTempProject([
            'src/HTTP/Request.php'          => <<<'PHP'
                <?php

                namespace App\HTTP;

                final class Request {}
                PHP,
            'src/Database/QueryBuilder.php' => <<<'PHP'
                <?php

                namespace App\Database;

                use App\HTTP\Request;

                final class QueryBuilder
                {
                    public function __construct(private Request $request) {}
                }
                PHP,
            'tests/HTTP/RequestTest.php'    => <<<'PHP'
                <?php

                namespace App\HTTP;

                use App\Database\QueryBuilder;

                final class RequestTest
                {
                    public function __construct(private QueryBuilder $db) {}
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('HTTP', ['src/HTTP/', 'tests/HTTP/'])
            ->layer('Database', 'src/Database/')
            ->ruleset([
                'HTTP'     => [], // Database NOT allowed
                'Database' => [], // HTTP NOT allowed
            ])
            ->skipPathsForRuleset(['*tests*']);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertTrue($ruleViolationCollection->hasViolations());
        $violations = $ruleViolationCollection->forRule('ruleset.Database');
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('App\\Database\\QueryBuilder', $violations[0]->message);
        $this->assertCount(0, $ruleViolationCollection->forRule('ruleset.HTTP'));
    }

    public function testRulesetPlusLayerSyntaxExpandsAllowedLayers(): void
    {
        $basePath = $this->makeTempProject([
            'src/RESTful/Resource.php' => <<<'PHP'
                <?php

                namespace App\RESTful;

                use App\Format\JsonFormatter;
                use App\Validation\Validator;

                final class Resource
                {
                    public function __construct(
                        private JsonFormatter $formatter,
                        private Validator $validator,
                    ) {}
                }
                PHP,
        ]);

        // RESTful uses +API and +Controller to inherit their allowed layers.
        // API allows Format; Controller allows Validation — both should be permitted for RESTful.
        $architecture = Architecture::define()
            ->layerPattern('RESTful', '/^App\\\\RESTful\\\\.*$/')
            ->layerPattern('Format', '/^App\\\\Format\\\\.*$/')
            ->layerPattern('Validation', '/^App\\\\Validation\\\\.*$/')
            ->layerPattern('API', '/^App\\\\API\\\\.*$/')
            ->layerPattern('Controller', '/^App\\\\Controller\\\\.*$/')
            ->ruleset([
                'API'        => ['Format'],
                'Controller' => ['Validation'],
                'RESTful'    => ['+API', '+Controller'],
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testRulesetPlusLayerSyntaxIncludesTargetLayerAndItsDependents(): void
    {
        // +API expands to: API (the layer itself) + Format (what API can depend on)
        // +Controller expands to: Controller (the layer itself) + Validation (what Controller can depend on)
        // So RESTful is allowed to depend on: API, Format, Controller, Validation
        $basePath = $this->makeTempProject([
            'src/API/Handler.php'          => '<?php namespace App\API; final class Handler {}',
            'src/Format/JsonFormatter.php' => '<?php namespace App\Format; final class JsonFormatter {}',
            'src/Controller/Base.php'      => '<?php namespace App\Controller; final class Base {}',
            'src/Validation/Validator.php' => '<?php namespace App\Validation; final class Validator {}',
            'src/RESTful/Resource.php'     => <<<'PHP'
                <?php

                namespace App\RESTful;

                use App\API\Handler;
                use App\Format\JsonFormatter;
                use App\Controller\Base;
                use App\Validation\Validator;

                final class Resource
                {
                    public function __construct(
                        private Handler $handler,
                        private JsonFormatter $formatter,
                        private Base $base,
                        private Validator $validator,
                    ) {}
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('RESTful', '/^App\\\\RESTful\\\\.*$/')
            ->layerPattern('API', '/^App\\\\API\\\\.*$/')
            ->layerPattern('Format', '/^App\\\\Format\\\\.*$/')
            ->layerPattern('Controller', '/^App\\\\Controller\\\\.*$/')
            ->layerPattern('Validation', '/^App\\\\Validation\\\\.*$/')
            ->ruleset([
                'API'        => ['Format'],
                'Controller' => ['Validation'],
                'RESTful'    => ['+API', '+Controller'],
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testRulesetPlusLayerSyntaxStillViolatesDisallowedLayers(): void
    {
        $basePath = $this->makeTempProject([
            'src/RESTful/Resource.php' => <<<'PHP'
                <?php

                namespace App\RESTful;

                use App\Database\QueryBuilder;

                final class Resource
                {
                    public function __construct(private QueryBuilder $db) {}
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('RESTful', '/^App\\\\RESTful\\\\.*$/')
            ->layerPattern('Database', '/^App\\\\Database\\\\.*$/')
            ->layerPattern('API', '/^App\\\\API\\\\.*$/')
            ->ruleset([
                'API'     => ['Format'],
                'RESTful' => ['+API'], // Database not in API's allowed list
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertTrue($ruleViolationCollection->hasViolations());
        $violations = $ruleViolationCollection->forRule('ruleset.RESTful');
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('Database', $violations[0]->message);
    }

    public function testRulesetPlusLayerSyntaxIgnoresUnknownReference(): void
    {
        $basePath = $this->makeTempProject([
            'src/RESTful/Resource.php' => <<<'PHP'
                <?php

                namespace App\RESTful;

                use App\Database\QueryBuilder;

                final class Resource
                {
                    public function __construct(private QueryBuilder $db) {}
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('RESTful', '/^App\\\\RESTful\\\\.*$/')
            ->layerPattern('Database', '/^App\\\\Database\\\\.*$/')
            ->ruleset([
                'RESTful' => ['+NonExistentLayer'], // unknown layer expands to nothing
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $violations = $ruleViolationCollection->forRule('ruleset.RESTful');
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('Database', $violations[0]->message);
    }

    public function testRulesetPlusLayerSyntaxHandlesCircularReference(): void
    {
        $basePath = $this->makeTempProject([
            'src/A/ClassA.php' => <<<'PHP'
                <?php

                namespace App\A;

                use App\B\ClassB;
                use App\Database\QueryBuilder;

                final class ClassA
                {
                    public function __construct(
                        private ClassB $b,
                        private QueryBuilder $qb,
                    ) {}
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('A', '/^App\\\\A\\\\.*$/')
            ->layerPattern('B', '/^App\\\\B\\\\.*$/')
            ->layerPattern('Database', '/^App\\\\Database\\\\.*$/')
            ->ruleset([
                'A' => ['+B'], // +B expands to: B (the layer itself)
                'B' => ['+A'], // circular: when expanding +A from inside B, A is guarded
            ]);

        // Must not hang or throw — circular refs are silently skipped.
        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        // +B includes B itself → A may depend on B: no violation for ClassB.
        // Database is not in the expanded allowed list → violation.
        $violations = $ruleViolationCollection->forRule('ruleset.A');
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('Database', $violations[0]->message);
    }

    public function testMayNotDependOnRuleViolationIsDetectedViaAnalyser(): void
    {
        $basePath = $this->makeTempProject([
            'src/Domain/Order.php'
        => '<?php
namespace App\Domain;
use App\Infrastructure\Persistence\OrderRepository;

class Order {
    public function __construct(private OrderRepository $repo) {}
}
',
            'src/Infrastructure/Persistence/OrderRepository.php'
        => '<?php namespace App\Infrastructure\Persistence; class OrderRepository {}
',
        ]);

        $architecture = Architecture::define()
            ->layer('Domain', 'src/Domain/')
            ->layer('Infrastructure', 'src/Infrastructure/')
            ->rule('domain.not_depend_infrastructure', new MayNotDependOnRule(from: 'Domain', to: 'Infrastructure'));

        $ruleViolationCollection = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential());

        $violations = $ruleViolationCollection->forRule('domain.not_depend_infrastructure');
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('Infrastructure', $violations[0]->message);
    }

    public function testMayNotDependOnRuleDetectsViolationWhenDependencyMatchesSecondaryLayer(): void
    {
        // AuthTokenStore must be scanned so it gets a ClassNode with layers
        // ['Support', 'Auth'] which is then stored in classLayerMap.
        $basePath = $this->makeTempProject([
            'src/HTTP/LoginController.php'   => <<<'PHP'
                <?php

                namespace App\HTTP;

                use App\Support\AuthTokenStore;

                final class LoginController
                {
                    public function __construct(private AuthTokenStore $store) {}
                }
                PHP,
            'src/Support/AuthTokenStore.php' => <<<'PHP'
                <?php

                namespace App\Support;

                final class AuthTokenStore {}
                PHP,
        ]);

        // AuthTokenStore matches both Support (primary, by namespace) and Auth (by class name).
        // The rule forbids Auth; only checking the primary Support layer misses the violation.
        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->layerPattern('Support', '/^App\\\\Support\\\\.*$/')
            ->layerPattern('Auth', '/Auth/')
            ->rule(
                'http.not_depend_auth',
                new MayNotDependOnRule(from: 'HTTP', to: 'Auth')
            );

        $ruleViolationCollection = (new Analyser($basePath))
            ->analyse($architecture, ['src/'], null, AnalyserOptions::sequential());

        $violations = $ruleViolationCollection->forRule('http.not_depend_auth');
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('Auth', $violations[0]->message);
    }

    public function testAnalyserRulesetDetectsViolationWhenDependencyMatchesSecondaryForbiddenLayer(): void
    {
        $basePath = $this->makeTempProject([
            'src/HTTP/LoginController.php' => <<<'PHP'
                <?php

                namespace App\HTTP;

                use App\Support\AuthTokenStore;

                final class LoginController
                {
                    public function __construct(private AuthTokenStore $store) {}
                }
                PHP,
        ]);

        // AuthTokenStore matches both Support (primary, allowed for HTTP) and Auth
        // (secondary, forbidden for HTTP). No file is needed for AuthTokenStore:
        // the ruleset path resolves dependency layers directly from the class name via
        // layerPattern(), so the class does not need to be scanned. Only resolving the
        // primary layer silently allows the violation.
        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->layerPattern('Support', '/^App\\\\Support\\\\.*$/')
            ->layerPattern('Auth', '/Auth/')
            ->ruleset([
                'HTTP' => ['Support'], // Auth is NOT in the allowed list
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertTrue($ruleViolationCollection->hasViolations());
        $violations = $ruleViolationCollection->forRule('ruleset.HTTP');
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('Auth', $violations[0]->message);
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
