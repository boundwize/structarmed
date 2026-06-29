<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Cli;

use Boundwize\StructArmed\Baseline\Baseline;
use Boundwize\StructArmed\Cache\AnalysisResultCache;
use Boundwize\StructArmed\Cli\AnalyseCommand;
use Boundwize\StructArmed\Cli\ClearCacheCommand;
use Boundwize\StructArmed\Cli\InitCommand;
use Boundwize\StructArmed\Cli\StructArmedApplication;
use Boundwize\StructArmed\Cli\Usage;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use Boundwize\StructArmed\Version;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_dir;
use function json_decode;
use function mkdir;
use function ob_get_clean;
use function ob_start;
use function preg_replace;
use function random_bytes;
use function rmdir;
use function serialize;
use function str_replace;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(AnalyseCommand::class)]
#[CoversClass(ClearCacheCommand::class)]
#[CoversClass(InitCommand::class)]
#[CoversClass(StructArmedApplication::class)]
#[CoversClass(Usage::class)]
#[CoversClass(Version::class)]
#[CoversClass(Baseline::class)]
final class StructArmedApplicationTest extends TestCase
{
    public function testApplicationClearsCacheWithoutAnalyseCommand(): void
    {
        $basePath = $this->createTempDirectory();

        try {
            [$exitCode, $output] = $this->runApplication(['structarmed', '--clear-cache'], $basePath);

            $this->assertSame(0, $exitCode);
            $this->assertStringContainsString('StructArmed cache cleared.', $output);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testApplicationClearsConfiguredCacheWithoutAnalyseCommand(): void
    {
        $basePath       = $this->createProjectDirectory();
        $cacheDirectory = $basePath . '/var/cache/structarmed';

        try {
            mkdir($basePath . '/var');
            mkdir($basePath . '/var/cache');
            mkdir($cacheDirectory);

            file_put_contents($cacheDirectory . '/key.json', '{}');
            file_put_contents($basePath . '/structarmed-custom.php', <<<'PHP'
<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;

return Architecture::define()
    ->cacheDirectory('var/cache/structarmed');
PHP);

            [$exitCode, $output] = $this->runApplication(
                ['structarmed', '--clear-cache', '--config=' . $basePath . '/structarmed-custom.php'],
                $basePath
            );

            $this->assertSame(0, $exitCode, $output);
            $this->assertStringContainsString('StructArmed cache cleared.', $output);
            $this->assertDirectoryDoesNotExist($cacheDirectory);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testApplicationClearsConfiguredCacheWithSeparateConfigOption(): void
    {
        $basePath       = $this->createProjectDirectory();
        $cacheDirectory = $basePath . '/var/cache/structarmed';

        try {
            mkdir($basePath . '/var');
            mkdir($basePath . '/var/cache');
            mkdir($cacheDirectory);

            file_put_contents($cacheDirectory . '/key.json', '{}');
            file_put_contents($basePath . '/structarmed-custom.php', <<<'PHP'
<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;

return Architecture::define()
    ->cacheDirectory('var/cache/structarmed');
PHP);

            [$exitCode, $output] = $this->runApplication(
                ['structarmed', '--clear-cache', '--config', $basePath . '/structarmed-custom.php'],
                $basePath
            );

            $this->assertSame(0, $exitCode, $output);
            $this->assertStringContainsString('StructArmed cache cleared.', $output);
            $this->assertDirectoryDoesNotExist($cacheDirectory);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function presetProvider(): iterable
    {
        yield 'default' => [
            [],
            '    ->withPreset(Preset::PSR4());',
        ];

        yield 'ddd with equals option' => [
            ['--preset=ddd'],
            '    ->withPreset(Preset::DDD());',
        ];

        yield 'mvc with separate option' => [
            ['--preset', 'mvc'],
            '    ->withPreset(Preset::MVC());',
        ];

        yield 'psr1' => [
            ['--preset=psr1'],
            '    ->withPreset(Preset::PSR1());',
        ];

        yield 'psr12' => [
            ['--preset=psr12'],
            '    ->withPreset(Preset::PSR12());',
        ];

        yield 'psr15' => [
            ['--preset=psr15'],
            '    ->withPreset(Preset::PSR15());',
        ];

        yield 'psr4' => [
            ['--preset=psr4'],
            '    ->withPreset(Preset::PSR4());',
        ];

        yield 'all' => [
            ['--preset=all'],
            "    ->withPresets(\n"
            . "        Preset::PSR1(),\n"
            . "        Preset::PSR12(),\n"
            . "        Preset::PSR15(),\n"
            . "        Preset::PSR4(),\n"
            . "        Preset::DDD(),\n"
            . "        Preset::MVC()\n"
            . "    );",
        ];
    }

    /**
     * @param list<string> $arguments
     */
    #[DataProvider('presetProvider')]
    public function testInitCommandGeneratesConfig(array $arguments, string $expectedPreset): void
    {
        $basePath = $this->createTempDirectory();

        try {
            [$exitCode, $output] = $this->runApplication(['structarmed', 'init', ...$arguments], $basePath);

            $this->assertSame(0, $exitCode, $output);
            $this->assertStringContainsString('Created structarmed.php', $output);
            $this->assertSame(
                $this->expectedConfig($expectedPreset),
                file_get_contents($basePath . '/structarmed.php')
            );
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testInitCommandReportsExistingConfig(): void
    {
        $basePath = $this->createTempDirectory();
        file_put_contents($basePath . '/structarmed.php', '<?php return null;');

        try {
            [$exitCode, $output] = $this->runApplication(['structarmed', 'init'], $basePath);

            $this->assertSame(0, $exitCode);
            $this->assertStringContainsString('structarmed.php already exists.', $output);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testAnalyseCommandRendersConsoleReport(): void
    {
        $basePath = $this->createProjectDirectory();

        try {
            [$exitCode, $output] = $this->runApplication(
                ['structarmed', 'analyse', 'src', '--config', $basePath . '/structarmed.php', '--no-progress'],
                $basePath
            );

            $this->assertSame(0, $exitCode, $output);
            $this->assertStringContainsString('StructArmed', $output);
            $this->assertStringContainsString('No violations found', $output);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testAnalyseCommandUsesProgressForConsoleReport(): void
    {
        $basePath = $this->createProjectDirectory();
        $progress = new class implements ProgressHandlerInterface {
            public bool $started = false;

            public bool $finished = false;

            public function start(int $total): void
            {
                $this->started = true;
            }

            public function advance(string $file): void
            {
            }

            public function finish(): void
            {
                $this->finished = true;
            }
        };

        try {
            [$exitCode, $output] = $this->runAnalyseCommand(
                ['--config=' . $basePath . '/structarmed.php'],
                $basePath,
                $progress
            );

            $this->assertSame(0, $exitCode, $output);
            $this->assertStringContainsString('No violations found', $output);
            $this->assertTrue($progress->started);
            $this->assertTrue($progress->finished);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testAnalyseCommandCanReuseCachedResult(): void
    {
        $basePath = $this->createProjectDirectory();
        $progress = new class implements ProgressHandlerInterface {
            public bool $started = false;

            public function start(int $total): void
            {
                $this->started = true;
            }

            public function advance(string $file): void
            {
            }

            public function finish(): void
            {
            }
        };

        try {
            [$firstExitCode, $firstOutput] = $this->runApplication(
                [
                    'structarmed',
                    'analyse',
                    '--config=' . $basePath . '/structarmed.php',
                    '--clear-cache',
                    '--no-progress',
                ],
                $basePath
            );

            [$secondExitCode, $secondOutput] = $this->runAnalyseCommand(
                ['--config=' . $basePath . '/structarmed.php'],
                $basePath,
                $progress
            );

            $this->assertSame(0, $firstExitCode, $firstOutput);
            $this->assertSame(0, $secondExitCode, $secondOutput);
            $this->assertStringContainsString('No violations found', $secondOutput);
            $this->assertFalse($progress->started);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testAnalyseCommandOnlyParsesNewFilesAfterCacheWarmup(): void
    {
        $basePath = $this->createProjectDirectory();
        mkdir($basePath . '/src/Domain');
        file_put_contents($basePath . '/src/Foo.php', <<<'PHP'
<?php namespace App; final class Foo { public function run(): void {} }
PHP);
        file_put_contents($basePath . '/src/Bar.php', '<?php namespace App; final class Bar {}');
        file_put_contents($basePath . '/structarmed.php', <<<'PHP'
<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;

return Architecture::define()
    ->layer('Source', 'src/');
PHP);

        $progress = new class implements ProgressHandlerInterface {
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

        try {
            [$firstExitCode, $firstOutput] = $this->runApplication(
                [
                    'structarmed',
                    'analyse',
                    '--config=' . $basePath . '/structarmed.php',
                    '--clear-cache',
                    '--no-progress',
                ],
                $basePath
            );

            file_put_contents($basePath . '/src/Baz.php', '<?php namespace App; final class Baz {}');

            [$secondExitCode, $secondOutput] = $this->runAnalyseCommand(
                ['--config=' . $basePath . '/structarmed.php'],
                $basePath,
                $progress
            );

            $this->assertSame(0, $firstExitCode, $firstOutput);
            $this->assertSame(0, $secondExitCode, $secondOutput);
            $this->assertSame(1, $progress->total);
            $this->assertCount(1, $progress->files);
            $this->assertStringEndsWith('/src/Baz.php', $this->normalisePath($progress->files[0]));
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    private function normalisePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    public function testAnalyseCommandStoresResultInConfiguredCacheDirectory(): void
    {
        $basePath       = $this->createProjectDirectory();
        $cacheDirectory = $basePath . '/var/cache/structarmed';

        file_put_contents($basePath . '/structarmed.php', <<<'PHP'
<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;

return Architecture::define()
    ->cacheDirectory('var/cache/structarmed');
PHP);

        try {
            [$exitCode, $output] = $this->runApplication(
                [
                    'structarmed',
                    'analyse',
                    '--config=' . $basePath . '/structarmed.php',
                    '--clear-cache',
                    '--no-progress',
                ],
                $basePath
            );

            $this->assertSame(0, $exitCode, $output);
            $this->assertDirectoryExists($cacheDirectory);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testAnalyseCommandRendersJsonReport(): void
    {
        $basePath = $this->createProjectDirectory();

        try {
            [$exitCode, $output] = $this->runApplication(
                ['structarmed', 'analyze', 'src', '--config=' . $basePath . '/structarmed.php', '--report=json'],
                $basePath
            );

            $this->assertSame(0, $exitCode, $output);

            $data = json_decode($output, true);
            $this->assertIsArray($data);
            $this->assertSame(0, $data['total']);
            $this->assertTrue($data['passed']);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testAnalyseCommandFixesFixableViolations(): void
    {
        $basePath = $this->createProjectDirectoryWithImplicitMethodVisibilityViolation();

        try {
            [$exitCode, $output] = $this->runApplication(
                [
                    'structarmed',
                    'analyze',
                    '--config=' . $basePath . '/structarmed.php',
                    '--fix',
                    '--no-progress',
                ],
                $basePath
            );

            $this->assertSame(0, $exitCode, $output);
            $this->assertMatchesRegularExpression(
                "/1 violation has been fixed\\.\\R\\RStructArmed/",
                $this->withoutAnsi($output)
            );
            $this->assertStringContainsString('No violations found', $output);
            $this->assertStringContainsString(
                '    public function handle(): void',
                (string) file_get_contents($basePath . '/src/Foo.php')
            );
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testAnalyseCommandReportsPluralFixedViolations(): void
    {
        $basePath = $this->createProjectDirectoryWithImplicitMethodVisibilityViolation(<<<'PHP'
<?php

namespace App;

class Foo
{
    function handle(): void
    {
    }

    function dispatch(): void
    {
    }
}
PHP);

        try {
            [$exitCode, $output] = $this->runApplication(
                [
                    'structarmed',
                    'analyze',
                    '--config=' . $basePath . '/structarmed.php',
                    '--fix',
                    '--no-progress',
                ],
                $basePath
            );

            $this->assertSame(0, $exitCode, $output);
            $this->assertStringContainsString('2 violations have been fixed.', $this->withoutAnsi($output));
            $this->assertStringContainsString(
                '    public function handle(): void',
                (string) file_get_contents($basePath . '/src/Foo.php')
            );
            $this->assertStringContainsString(
                '    public function dispatch(): void',
                (string) file_get_contents($basePath . '/src/Foo.php')
            );
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testAnalyseCommandReportsFixHintForFixableViolations(): void
    {
        $basePath = $this->createProjectDirectoryWithImplicitMethodVisibilityViolation();

        try {
            [$exitCode, $output] = $this->runApplication(
                [
                    'structarmed',
                    'analyze',
                    '--config=' . $basePath . '/structarmed.php',
                    '--no-progress',
                ],
                $basePath
            );

            $this->assertSame(1, $exitCode, $output);
            $this->assertStringContainsString('Hint: run again with --fix', $output);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testAnalyseCommandDoesNotFixNonFixableViolations(): void
    {
        $basePath = $this->createProjectDirectoryWithNonFixableViolation();

        try {
            [$exitCode, $output] = $this->runApplication(
                [
                    'structarmed',
                    'analyze',
                    '--config=' . $basePath . '/structarmed.php',
                    '--fix',
                    '--no-progress',
                ],
                $basePath
            );

            $this->assertSame(1, $exitCode, $output);
            $this->assertStringNotContainsString('violation has been fixed', $this->withoutAnsi($output));
            $this->assertStringContainsString('Class [App\\Foo] must have suffix [Service]', $output);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testAnalyseCommandGeneratesBaseline(): void
    {
        $basePath = $this->createProjectDirectoryWithViolation();

        try {
            [$exitCode, $output] = $this->runApplication(
                [
                    'structarmed',
                    'analyse',
                    '--config=' . $basePath . '/structarmed.php',
                    '--no-progress',
                    '--generate-baseline=structarmed-baseline.php',
                ],
                $basePath
            );

            $this->assertSame(0, $exitCode, $output);
            $this->assertStringContainsString(
                'Generated baseline [structarmed-baseline.php] with 1 violation(s).',
                $output
            );
            $this->assertFileExists($basePath . '/structarmed-baseline.php');
            $baseline = file_get_contents($basePath . '/structarmed-baseline.php');

            $this->assertIsString($baseline);
            $this->assertStringContainsString("'file' => 'src/Foo.php'", $baseline);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testAnalyseCommandGeneratesBaselineWithSeparateOptionValue(): void
    {
        $basePath = $this->createProjectDirectoryWithViolation();

        try {
            [$exitCode, $output] = $this->runApplication(
                [
                    'structarmed',
                    'analyse',
                    '--config=' . $basePath . '/structarmed.php',
                    '--no-progress',
                    '--generate-baseline',
                    'structarmed-baseline.php',
                ],
                $basePath
            );

            $this->assertSame(0, $exitCode, $output);
            $this->assertStringContainsString(
                'Generated baseline [structarmed-baseline.php] with 1 violation(s).',
                $output
            );
            $this->assertFileExists($basePath . '/structarmed-baseline.php');
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testAnalyseCommandFixesViolationsBeforeGeneratingBaseline(): void
    {
        $basePath = $this->createProjectDirectoryWithImplicitMethodVisibilityViolation();

        try {
            [$exitCode, $output] = $this->runApplication(
                [
                    'structarmed',
                    'analyse',
                    '--config=' . $basePath . '/structarmed.php',
                    '--fix',
                    '--no-progress',
                    '--generate-baseline=structarmed-baseline.php',
                ],
                $basePath
            );

            $this->assertSame(0, $exitCode, $output);
            $this->assertStringContainsString(
                'Generated baseline [structarmed-baseline.php] with 0 violation(s).',
                $output
            );
            $this->assertStringContainsString(
                '    public function handle(): void',
                (string) file_get_contents($basePath . '/src/Foo.php')
            );
            $this->assertSame(
                <<<'PHP'
<?php

declare(strict_types=1);

return [];

PHP,
                file_get_contents($basePath . '/structarmed-baseline.php')
            );
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testAnalyseCommandReportsFixedCountWhenGeneratingBaselineAfterFix(): void
    {
        $basePath = $this->createProjectDirectoryWithImplicitMethodVisibilityViolation();

        try {
            [$exitCode, $output] = $this->runApplication(
                [
                    'structarmed',
                    'analyse',
                    '--config=' . $basePath . '/structarmed.php',
                    '--fix',
                    '--no-progress',
                    '--generate-baseline=structarmed-baseline.php',
                ],
                $basePath
            );

            $this->assertSame(0, $exitCode, $output);
            $this->assertStringContainsString(
                '1 violation has been fixed.',
                $this->withoutAnsi($output)
            );
            $this->assertStringContainsString(
                'Generated baseline [structarmed-baseline.php] with 0 violation(s).',
                $output
            );
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testAnalyseCommandCanFixAndGenerateMissingConfiguredBaseline(): void
    {
        $basePath = $this->createProjectDirectoryWithImplicitMethodVisibilityViolation();

        try {
            file_put_contents($basePath . '/structarmed.php', <<<'PHP'
<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Rules\Class_\MustDeclareMethodVisibilityRule;

return Architecture::define()
    ->baseline('structarmed-baseline.php')
    ->layer('Source', 'src/')
    ->rule('source.must_declare_method_visibility', new MustDeclareMethodVisibilityRule('Source'));
PHP);

            [$exitCode, $output] = $this->runApplication(
                [
                    'structarmed',
                    'analyse',
                    '--config=' . $basePath . '/structarmed.php',
                    '--fix',
                    '--no-progress',
                    '--generate-baseline=structarmed-baseline.php',
                ],
                $basePath
            );

            $this->assertSame(0, $exitCode, $output);
            $this->assertStringContainsString(
                'Generated baseline [structarmed-baseline.php] with 0 violation(s).',
                $output
            );
            $this->assertStringContainsString(
                '    public function handle(): void',
                (string) file_get_contents($basePath . '/src/Foo.php')
            );
            $this->assertFileExists($basePath . '/structarmed-baseline.php');
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testAnalyseCommandGeneratesBaselineFromRemainingViolationsAfterFix(): void
    {
        $basePath = $this->createProjectDirectoryWithImplicitMethodVisibilityViolation();

        try {
            file_put_contents($basePath . '/structarmed.php', <<<'PHP'
<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Rules\Class_\ClassNameMustHaveSuffixRule;
use Boundwize\StructArmed\Rule\Rules\Class_\MustDeclareMethodVisibilityRule;

return Architecture::define()
    ->layer('Source', 'src/')
    ->rule('source.must_declare_method_visibility', new MustDeclareMethodVisibilityRule('Source'))
    ->rule('source.class_name_must_have_suffix', new ClassNameMustHaveSuffixRule('Source', 'Service'));
PHP);

            [$exitCode, $output] = $this->runApplication(
                [
                    'structarmed',
                    'analyse',
                    '--config=' . $basePath . '/structarmed.php',
                    '--fix',
                    '--no-progress',
                    '--generate-baseline=structarmed-baseline.php',
                ],
                $basePath
            );

            $this->assertSame(0, $exitCode, $output);
            $this->assertStringContainsString(
                'Generated baseline [structarmed-baseline.php] with 1 violation(s).',
                $output
            );
            $this->assertStringContainsString(
                '    public function handle(): void',
                (string) file_get_contents($basePath . '/src/Foo.php')
            );

            $baseline = file_get_contents($basePath . '/structarmed-baseline.php');

            $this->assertIsString($baseline);
            $this->assertStringContainsString("'rule' => 'source.class_name_must_have_suffix'", $baseline);
            $this->assertStringContainsString("'message' => 'Class [App\\Foo] must have suffix [Service]'", $baseline);
            $this->assertStringNotContainsString('source.must_declare_method_visibility', $baseline);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testAnalyseCommandReportsBaselineGenerationFailure(): void
    {
        $basePath = $this->createProjectDirectoryWithViolation();

        try {
            [$exitCode, $output] = $this->runApplication(
                [
                    'structarmed',
                    'analyse',
                    '--config=' . $basePath . '/structarmed.php',
                    '--no-progress',
                    '--generate-baseline=missing/structarmed-baseline.php',
                ],
                $basePath
            );

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString(
                'Error: Baseline directory [' . $basePath . '/missing] does not exist.',
                $output
            );
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testAnalyseCommandFiltersConfiguredBaseline(): void
    {
        $basePath = $this->createProjectDirectoryWithViolation();

        try {
            [$generateExitCode, $generateOutput] = $this->runApplication(
                [
                    'structarmed',
                    'analyse',
                    '--config=' . $basePath . '/structarmed.php',
                    '--no-progress',
                    '--generate-baseline=structarmed-baseline.php',
                ],
                $basePath
            );

            file_put_contents($basePath . '/structarmed.php', <<<'PHP'
<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeFinalRule;

return Architecture::define()
    ->baseline('structarmed-baseline.php')
    ->layer('Source', 'src/')
    ->rule('source.must_be_final', new MustBeFinalRule('Source'));
PHP);

            [$exitCode, $output] = $this->runApplication(
                [
                    'structarmed',
                    'analyse',
                    '--config=' . $basePath . '/structarmed.php',
                    '--clear-cache',
                    '--no-progress',
                ],
                $basePath
            );

            $this->assertSame(0, $generateExitCode, $generateOutput);
            $this->assertSame(0, $exitCode, $output);
            $this->assertStringContainsString('No violations found', $output);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testAnalyseCommandReportsConfiguredBaselineFailure(): void
    {
        $basePath = $this->createProjectDirectory();

        try {
            file_put_contents($basePath . '/structarmed.php', <<<'PHP'
<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;

return Architecture::define()
    ->baseline('missing-baseline.php');
PHP);

            [$exitCode, $output] = $this->runApplication(
                [
                    'structarmed',
                    'analyse',
                    '--config=' . $basePath . '/structarmed.php',
                    '--clear-cache',
                    '--no-progress',
                ],
                $basePath
            );

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString(
                'Error: Baseline file [missing-baseline.php] does not exist.',
                $output
            );
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testAnalyseCommandReportsConfiguredBaselineFailureBeforeFix(): void
    {
        $basePath = $this->createProjectDirectoryWithImplicitMethodVisibilityViolation();

        try {
            file_put_contents($basePath . '/structarmed.php', <<<'PHP'
<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Rules\Class_\MustDeclareMethodVisibilityRule;

return Architecture::define()
    ->baseline('missing-baseline.php')
    ->layer('Source', 'src/')
    ->rule('source.must_declare_method_visibility', new MustDeclareMethodVisibilityRule('Source'));
PHP);

            [$exitCode, $output] = $this->runApplication(
                [
                    'structarmed',
                    'analyse',
                    '--config=' . $basePath . '/structarmed.php',
                    '--fix',
                    '--no-progress',
                ],
                $basePath
            );

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString(
                'Error: Baseline file [missing-baseline.php] does not exist.',
                $output
            );
            $this->assertStringContainsString(
                '    function handle(): void',
                (string) file_get_contents($basePath . '/src/Foo.php')
            );
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testAnalyseCommandReportsConfiguredBaselineFailureAfterFix(): void
    {
        $basePath = $this->createProjectDirectoryWithViolation();

        try {
            file_put_contents($basePath . '/structarmed-baseline.php', '<?php return [];');
            file_put_contents($basePath . '/structarmed.php', <<<'PHP'
<?php

declare(strict_types=1);

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\FixableInterface;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

return Architecture::define()
    ->baseline('structarmed-baseline.php')
    ->layer('Source', 'src/')
    ->rule(
        'source.fixable_failure',
        new class (__DIR__ . '/structarmed-baseline.php') implements RuleInterface, FixableInterface {
        public function __construct(private readonly string $baselineFile)
        {
        }

        public function appliesTo(ClassNode $classNode): bool
        {
            return true;
        }

        public function evaluate(ClassNode $classNode): ?RuleViolation
        {
            return new RuleViolation(
                message:   'Fixable failure',
                file:      $classNode->file,
                line:      $classNode->line,
                className: $classNode->className,
                layer:     $classNode->layer,
                ruleKey:   'source.fixable_failure',
            );
        }

        public function fix(RuleViolation $ruleViolation): bool
        {
            unlink($this->baselineFile);

            return true;
        }
    }
    );
PHP);

            [$exitCode, $output] = $this->runApplication(
                [
                    'structarmed',
                    'analyse',
                    '--config=' . $basePath . '/structarmed.php',
                    '--clear-cache',
                    '--fix',
                    '--no-progress',
                ],
                $basePath
            );

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString(
                'Error: Baseline file [structarmed-baseline.php] does not exist.',
                $output
            );
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testAnalyseCommandAcceptsSeparateReportOption(): void
    {
        $basePath = $this->createProjectDirectory();

        try {
            [$exitCode, $output] = $this->runApplication(
                ['structarmed', 'analyse', '--config=' . $basePath . '/structarmed.php', '--report', 'json'],
                $basePath
            );

            $this->assertSame(0, $exitCode, $output);
            $this->assertJson($output);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testAnalyseCommandDisablesParallel(): void
    {
        $basePath = $this->createProjectDirectory();

        // Add multiple files so parallel would normally be triggered
        file_put_contents($basePath . '/src/Foo.php', '<?php namespace App; final class Foo {}');
        file_put_contents($basePath . '/src/Bar.php', '<?php namespace App; final class Bar {}');

        $progress = new class implements ProgressHandlerInterface {
            public int $fileCount = 0;

            public function start(int $total): void
            {
            }

            public function advance(string $file): void
            {
                $this->fileCount++;
            }

            public function finish(): void
            {
            }
        };

        try {
            [$exitCode, $output] = $this->runAnalyseCommand(
                [
                    'src',
                    '--config=' . $basePath . '/structarmed.php',
                    '--disable-parallel',
                    '--clear-cache',
                ],
                $basePath,
                $progress
            );

            $this->assertSame(0, $exitCode, $output);
            $this->assertStringContainsString('No violations found', $output);
            // Both files were processed sequentially through the progress handler
            $this->assertSame(2, $progress->fileCount);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testAnalyseCommandCanAnalyseSingleFile(): void
    {
        $basePath = $this->createProjectDirectoryWithViolation();

        try {
            [$exitCode, $output] = $this->runApplication(
                [
                    'structarmed',
                    'analyse',
                    'src/Foo.php',
                    '--config=' . $basePath . '/structarmed.php',
                    '--no-progress',
                ],
                $basePath
            );

            $this->assertSame(1, $exitCode, $output);
            $this->assertStringContainsString('violation', $output);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testAnalyseCommandRejectsNonPhpFileScanPath(): void
    {
        $basePath   = $this->createTempDirectory();
        $readmePath = $basePath . '/readme.md';

        file_put_contents($readmePath, '# Readme');

        try {
            [$exitCode, $output] = $this->runApplication(
                ['structarmed', 'analyse', 'readme.md'],
                $basePath
            );

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString('Error: path [readme.md] not found.', $output);
        } finally {
            unlink($readmePath);
            rmdir($basePath);
        }
    }

    public function testInternalWorkerRoutesDelegatestoClassNodeWorker(): void
    {
        $inputFile  = (string) tempnam(sys_get_temp_dir(), 'structarmed-worker-input-');
        $outputFile = (string) tempnam(sys_get_temp_dir(), 'structarmed-worker-output-');

        file_put_contents($inputFile, serialize([
            'basePath'      => sys_get_temp_dir(),
            'layers'        => [],
            'layerPatterns' => [],
            'files'         => [],
        ]));

        try {
            [$exitCode] = $this->runApplication(['structarmed', '--internal-worker', $inputFile, $outputFile]);

            $this->assertSame(0, $exitCode);
        } finally {
            @unlink($inputFile);
            @unlink($outputFile);
        }
    }

    /**
     * @param list<string> $argv
     * @return array{int, string}
     */
    private function runApplication(array $argv, ?string $basePath = null): array
    {
        ob_start();
        $exitCode = (new StructArmedApplication())->run($argv, $basePath);
        $output   = ob_get_clean();
        $this->assertIsString($output);

        return [$exitCode, $output];
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string}
     */
    private function runAnalyseCommand(
        array $arguments,
        string $basePath,
        ProgressHandlerInterface $progressHandler
    ): array {
        ob_start();
        $exitCode = (new AnalyseCommand($progressHandler))->run($arguments, $basePath);
        $output   = ob_get_clean();
        $this->assertIsString($output);

        return [$exitCode, $output];
    }

    private function createProjectDirectory(): string
    {
        $basePath = $this->createTempDirectory();
        mkdir($basePath . '/src');
        file_put_contents($basePath . '/structarmed.php', <<<'PHP'
<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;

return Architecture::define();
PHP);

        return $basePath;
    }

    private function createProjectDirectoryWithViolation(): string
    {
        $basePath = $this->createProjectDirectory();

        file_put_contents($basePath . '/src/Foo.php', <<<'PHP'
<?php

namespace App;

class Foo
{
}
PHP);

        file_put_contents($basePath . '/structarmed.php', <<<'PHP'
<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeFinalRule;

return Architecture::define()
    ->layer('Source', 'src/')
    ->rule('source.must_be_final', new MustBeFinalRule('Source'));
PHP);

        return $basePath;
    }

    private function createProjectDirectoryWithNonFixableViolation(): string
    {
        $basePath = $this->createProjectDirectory();

        file_put_contents($basePath . '/src/Foo.php', <<<'PHP'
<?php

namespace App;

class Foo
{
}
PHP);

        file_put_contents($basePath . '/structarmed.php', <<<'PHP'
<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Rules\Class_\ClassNameMustHaveSuffixRule;

return Architecture::define()
    ->layer('Source', 'src/')
    ->rule('source.class_name_must_have_suffix', new ClassNameMustHaveSuffixRule('Source', 'Service'));
PHP);

        return $basePath;
    }

    private function createProjectDirectoryWithImplicitMethodVisibilityViolation(?string $source = null): string
    {
        $basePath = $this->createProjectDirectory();

        file_put_contents($basePath . '/src/Foo.php', $source ?? <<<'PHP'
<?php

namespace App;

class Foo
{
    function handle(): void
    {
    }
}
PHP);

        file_put_contents($basePath . '/structarmed.php', <<<'PHP'
<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Rules\Class_\MustDeclareMethodVisibilityRule;

return Architecture::define()
    ->layer('Source', 'src/')
    ->rule('source.must_declare_method_visibility', new MustDeclareMethodVisibilityRule('Source'));
PHP);

        return $basePath;
    }

    private function withoutAnsi(string $value): string
    {
        return (string) preg_replace('/\e\[[0-9;]*m/', '', $value);
    }

    private function createTempDirectory(): string
    {
        $basePath = sys_get_temp_dir() . '/structarmed-cli-' . bin2hex(random_bytes(6));
        mkdir($basePath);

        return $basePath;
    }

    private function expectedConfig(string $presetConfig): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\Preset;

return Architecture::define()
{$presetConfig}

PHP;
    }

    private function removeTempDirectory(string $basePath): void
    {
        (new AnalysisResultCache($basePath))->clear();

        if (file_exists($basePath . '/structarmed.php')) {
            unlink($basePath . '/structarmed.php');
        }

        if (file_exists($basePath . '/structarmed-custom.php')) {
            unlink($basePath . '/structarmed-custom.php');
        }

        if (file_exists($basePath . '/structarmed-baseline.php')) {
            unlink($basePath . '/structarmed-baseline.php');
        }

        foreach (glob($basePath . '/var/cache/structarmed/*.json') ?: [] as $cacheFile) {
            unlink($cacheFile);
        }

        foreach (glob($basePath . '/src/*.php') ?: [] as $sourceFile) {
            unlink($sourceFile);
        }

        if (is_dir($basePath . '/src/Domain')) {
            rmdir($basePath . '/src/Domain');
        }

        if (is_dir($basePath . '/src')) {
            rmdir($basePath . '/src');
        }

        if (is_dir($basePath . '/var/cache/structarmed')) {
            rmdir($basePath . '/var/cache/structarmed');
        }

        if (is_dir($basePath . '/var/cache')) {
            rmdir($basePath . '/var/cache');
        }

        if (is_dir($basePath . '/var')) {
            rmdir($basePath . '/var');
        }

        if (is_dir($basePath)) {
            rmdir($basePath);
        }
    }
}
