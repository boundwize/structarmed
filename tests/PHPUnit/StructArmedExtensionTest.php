<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\PHPUnit;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Baseline\BaselineFilter;
use Boundwize\StructArmed\Exception\ViolationsFoundException;
use Boundwize\StructArmed\PHPUnit\StructArmedExtension;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeFinalRule;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use Boundwize\StructArmed\Util\Path;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use ReflectionClass;

use function chdir;
use function file_get_contents;
use function file_put_contents;
use function getcwd;
use function getenv;
use function glob;
use function json_encode;
use function mkdir;
use function ob_get_clean;
use function ob_start;
use function preg_quote;
use function preg_replace;
use function putenv;
use function unlink;
use function var_export;

#[CoversClass(StructArmedExtension::class)]
#[CoversClass(BaselineFilter::class)]
final class StructArmedExtensionTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testBootstrapPrintsPassingReport(): void
    {
        $configPath   = $this->writeConfig(
            'return ' . Architecture::class . "::define()\n"
            . $this->cacheDirectoryCall() . ';'
        );
        $previousPath = getcwd();
        $this->assertIsString($previousPath);

        // an empty project: from the repository root, its composer.json would
        // put every source and test file of this package under analysis
        chdir($this->makeTemporaryDirectory('structarmed-extension'));

        try {
            $this->expectOutputRegex('/No violations found/');

            (new StructArmedExtension())->bootstrap(
                $this->configuration(),
                new Facade(),
                $this->parameters($configPath)
            );
        } finally {
            chdir($previousPath);
        }
    }

    private string|false $originalDisabledValue = false;

    protected function setUp(): void
    {
        // the suite itself may run with the extension disabled, eg: on CI
        $this->originalDisabledValue = getenv('STRUCTARMED_DISABLED');
        putenv('STRUCTARMED_DISABLED');
    }

    #[After]
    protected function restoreDisabledEnvironmentVariable(): void
    {
        putenv(
            $this->originalDisabledValue === false
                ? 'STRUCTARMED_DISABLED'
                : 'STRUCTARMED_DISABLED=' . $this->originalDisabledValue
        );
    }

    public function testBootstrapDoesNothingWhenDisabledByEnvironmentVariable(): void
    {
        putenv('STRUCTARMED_DISABLED=1');

        $this->expectOutputString('');

        (new StructArmedExtension())->bootstrap(
            $this->configuration(),
            new Facade(),
            $this->parameters('/missing/structarmed.php')
        );
    }

    public function testBootstrapDiscoversConfigWhenParameterIsMissing(): void
    {
        $basePath     = $this->makeTempProjectConfig(
            'return ' . Architecture::class . "::define()\n"
            . $this->cacheDirectoryCall() . ';'
        );
        $previousPath = getcwd();
        $this->assertIsString($previousPath);

        chdir($basePath);

        try {
            $this->expectOutputRegex('/No violations found/');

            (new StructArmedExtension())->bootstrap(
                $this->configuration(),
                new Facade(),
                $this->parameters()
            );
        } finally {
            chdir($previousPath);
        }
    }

    public function testBootstrapPrintsAbsoluteConfigurationFileForRelativeConfigParameter(): void
    {
        $basePath     = $this->makeTempProjectConfig(
            'return ' . Architecture::class . "::define()\n"
            . $this->cacheDirectoryCall() . ';'
        );
        $previousPath = getcwd();
        $this->assertIsString($previousPath);

        chdir($basePath);

        try {
            $this->expectOutputRegex(
                '/^Configuration: ' . preg_quote(Path::normalise($basePath, canonicalise: true), '/')
                . '\/structarmed\.php\R/m'
            );

            (new StructArmedExtension())->bootstrap(
                $this->configuration(),
                new Facade(),
                $this->parameters('structarmed.php')
            );
        } finally {
            chdir($previousPath);
        }
    }

    public function testBootstrapUsesCacheDirectoryFromConfig(): void
    {
        $previousPath = getcwd();
        $this->assertIsString($previousPath);

        $basePath = $this->makeTemporaryDirectory('structarmed-extension-project');
        $srcDir   = $basePath . '/src';
        mkdir($srcDir);
        file_put_contents($srcDir . '/Foo.php', "<?php\nclass Foo {}\n");

        $cacheDir   = $this->registerTemporaryPath($basePath . '/custom-cache');
        $configPath = $basePath . '/structarmed.php';
        file_put_contents(
            $configPath,
            "<?php\nreturn " . Architecture::class . "::define()"
            . "->layer('App', 'src/')"
            . "->cacheDirectory('" . $cacheDir . "');\n"
        );

        chdir($basePath);

        try {
            $this->expectOutputRegex('/No violations found/');

            (new StructArmedExtension())->bootstrap(
                $this->configuration(),
                new Facade(),
                $this->parameters($configPath)
            );
        } finally {
            chdir($previousPath);
        }

        $this->assertDirectoryExists($cacheDir);
    }

    public function testBootstrapClearsCacheWhenConfigChanges(): void
    {
        $previousPath = getcwd();
        $this->assertIsString($previousPath);

        $basePath = $this->makeTemporaryDirectory('structarmed-extension-stale');
        $srcDir   = $basePath . '/src';
        mkdir($srcDir);
        file_put_contents($srcDir . '/Bar.php', "<?php\nclass Bar {}\n");

        $cacheDir   = $basePath . '/custom-cache';
        $configPath = $basePath . '/structarmed.php';
        file_put_contents(
            $configPath,
            "<?php\nreturn " . Architecture::class . "::define()"
            . "->layer('App', 'src/')"
            . "->cacheDirectory('" . $cacheDir . "');\n"
        );

        mkdir($cacheDir, 0777, true);
        file_put_contents($cacheDir . '/stale.json', json_encode([
            'metadata'   => ['configHash' => 'stale-hash-that-will-not-match'],
            'violations' => [],
        ]));

        chdir($basePath);

        try {
            $this->expectOutputRegex('/No violations found/');

            (new StructArmedExtension())->bootstrap(
                $this->configuration(),
                new Facade(),
                $this->parameters($configPath)
            );
        } finally {
            chdir($previousPath);
        }
    }

    public function testBootstrapWithNoCacheParameterDoesNotWriteCache(): void
    {
        $previousPath = getcwd();
        $this->assertIsString($previousPath);

        $basePath = $this->makeProjectWithCacheDirectory('structarmed-extension-no-cache');

        chdir($basePath);

        try {
            $this->expectOutputRegex('/No violations found/');

            (new StructArmedExtension())->bootstrap(
                $this->configuration(),
                new Facade(),
                $this->parameters($basePath . '/structarmed.php', noCache: true)
            );
        } finally {
            chdir($previousPath);
        }

        $this->assertDirectoryDoesNotExist($basePath . '/custom-cache');
    }

    public function testBootstrapWithNoCacheParameterKeepsExistingCache(): void
    {
        $previousPath = getcwd();
        $this->assertIsString($previousPath);

        $basePath      = $this->makeProjectWithCacheDirectory('structarmed-extension-keep-cache');
        $configPath    = $basePath . '/structarmed.php';
        $cacheContents = [];

        chdir($basePath);

        try {
            $this->expectOutputRegex('/No violations found.*Class \[Bar\] must be declared final/s');

            (new StructArmedExtension())->bootstrap(
                $this->configuration(),
                new Facade(),
                $this->parameters($configPath)
            );
            $cacheContents = $this->directoryContents($basePath . '/custom-cache');

            // A cached run would now write the node cache of the new file,
            // the uncached run has to analyse it to report its violation.
            file_put_contents($basePath . '/src/Bar.php', <<<'PHP'
                <?php

                class Bar
                {
                }

                PHP);

            (new StructArmedExtension())->bootstrap(
                $this->configuration(),
                new Facade(),
                $this->parameters($configPath, noCache: true)
            );

            $this->fail('The uncached run did not report the violation of the new file.');
        } catch (ViolationsFoundException $violationsFoundException) {
            $this->assertStringContainsString(
                'StructArmed found 1 architecture violation.',
                $violationsFoundException->getMessage()
            );
        } finally {
            chdir($previousPath);
        }

        $this->assertNotSame([], $cacheContents);
        $this->assertSame($cacheContents, $this->directoryContents($basePath . '/custom-cache'));
    }

    public function testBootstrapThrowsWhenViolationsAreFound(): void
    {
        $configPath   = $this->writeConfig(
            'return ' . Architecture::class . "::define()\n"
            . "    ->layer('Domain', 'src/Domain/')\n"
            . "    ->rule('must_be_final', new " . MustBeFinalRule::class . "('Domain'))\n"
            . $this->cacheDirectoryCall() . ';'
        );
        $previousPath = getcwd();
        $this->assertIsString($previousPath);

        // the sample project has no composer.json, so only its Domain layer is analysed
        chdir(__DIR__ . '/../Fixtures/sample');

        try {
            $this->expectException(ViolationsFoundException::class);
            $this->expectExceptionMessage('StructArmed found');
            $this->expectOutputRegex('/Found \d+ violation/');

            (new StructArmedExtension())->bootstrap(
                $this->configuration(),
                new Facade(),
                $this->parameters($configPath)
            );
        } finally {
            chdir($previousPath);
        }
    }

    public function testBootstrapDoesNotThrowWhenViolationsAreBaselined(): void
    {
        $previousPath = getcwd();
        $this->assertIsString($previousPath);

        $basePath = $this->makeTemporaryDirectory('structarmed-extension-baselined');
        mkdir($basePath . '/src');
        file_put_contents($basePath . '/src/Foo.php', "<?php\n\nnamespace App;\n\nclass Foo\n{\n}\n");
        file_put_contents($basePath . '/structarmed-baseline.php', <<<'PHP'
<?php

declare(strict_types=1);

return [
    [
        'rule'    => 'source.must_be_final',
        'message' => 'Class [App\Foo] must be declared final',
        'file'    => 'src/Foo.php',
        'line'    => 5,
        'class'   => 'App\Foo',
        'layer'   => 'Source',
    ],
];
PHP);

        $configPath = $basePath . '/structarmed.php';
        file_put_contents(
            $configPath,
            '<?php' . "\n\nreturn " . Architecture::class . "::define()\n"
            . "    ->layer('Source', 'src/')\n"
            . "    ->rule('source.must_be_final', new " . MustBeFinalRule::class . "('Source'))\n"
            . "    ->baseline('structarmed-baseline.php')\n"
            . $this->cacheDirectoryCall() . ";\n"
        );

        chdir($basePath);

        try {
            $this->expectOutputRegex('/No violations found/');

            (new StructArmedExtension())->bootstrap(
                $this->configuration(),
                new Facade(),
                $this->parameters($configPath)
            );
        } finally {
            chdir($previousPath);
        }
    }

    public function testBootstrapWarnsWithoutBlockingWhenBaselineFileMissing(): void
    {
        $configPath   = $this->writeConfig(
            'return ' . Architecture::class . "::define()\n"
            . "    ->baseline('missing-baseline.php')\n"
            . $this->cacheDirectoryCall() . ';'
        );
        $previousPath = getcwd();
        $this->assertIsString($previousPath);

        chdir($this->makeTemporaryDirectory('structarmed-extension'));

        try {
            $this->expectOutputRegex(
                // The warning label is yellow when colors are supported.
                '/No violations found.*Warning:(\e\\[0m)? Baseline file \\[missing-baseline\\.php\\] does not exist/s'
            );

            (new StructArmedExtension())->bootstrap(
                $this->configuration(),
                new Facade(),
                $this->parameters($configPath)
            );
        } finally {
            chdir($previousPath);
        }
    }

    public function testBootstrapPrintsBaselineInfoOnlyWithBaselineInfoParameter(): void
    {
        $previousPath = getcwd();
        $this->assertIsString($previousPath);

        $basePath   = $this->makeTemporaryDirectory('structarmed-extension-baseline-info');
        $configPath = $basePath . '/structarmed.php';
        mkdir($basePath . '/src');
        file_put_contents($basePath . '/src/Foo.php', <<<'PHP'
            <?php

            namespace App;

            class Foo
            {
            }

            PHP);
        file_put_contents($basePath . '/src/Bar.php', <<<'PHP'
            <?php

            namespace App;

            class Bar
            {
            }

            PHP);
        file_put_contents($basePath . '/structarmed-baseline.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                [
                    'rule'    => 'source.must_be_final',
                    'message' => 'Class [App\Foo] must be declared final',
                    'file'    => 'src/Foo.php',
                    'class'   => 'App\Foo',
                    'layer'   => 'Source',
                ],
                [
                    'rule'    => 'source.must_be_final',
                    'message' => 'Class [App\Bar] must be declared final',
                    'file'    => 'src/Bar.php',
                    'class'   => 'App\Bar',
                    'layer'   => 'Source',
                ],
            ];

            PHP);
        file_put_contents($configPath, <<<'PHP'
            <?php

            use Boundwize\StructArmed\Architecture;
            use Boundwize\StructArmed\Rule\Rules\Class_\MustBeFinalRule;

            return Architecture::define()
                ->layer('Source', 'src/')
                ->rule('source.must_be_final', new MustBeFinalRule('Source'))
                ->baseline('structarmed-baseline.php');

            PHP);

        $withBaselineInfo    = $this->parameters($configPath, noCache: true, baselineInfo: true);
        $withoutBaselineInfo = $this->parameters($configPath, noCache: true);

        chdir($basePath);

        try {
            $output                 = $this->bootstrapOutput($withBaselineInfo);
            $outputWithoutParameter = $this->bootstrapOutput($withoutBaselineInfo);

            file_put_contents($basePath . '/src/Bar.php', <<<'PHP'
                <?php

                namespace App;

                final class Bar
                {
                }

                PHP);
            $staleOutput = $this->bootstrapOutput($withBaselineInfo);

            file_put_contents($basePath . '/src/Foo.php', <<<'PHP'
                <?php

                namespace App;

                final class Foo
                {
                }

                PHP);
            unlink($basePath . '/structarmed-baseline.php');
            $missingOutput = $this->bootstrapOutput($withBaselineInfo);

            file_put_contents($configPath, <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;

                return Architecture::define()
                    ->layer('Source', 'src/');

                PHP);
            $notConfiguredOutput = $this->bootstrapOutput($withBaselineInfo);
        } finally {
            chdir($previousPath);
        }

        // Each line ends with a blank line, to stay apart from the PHPUnit output that follows.
        $this->assertStringEndsWith(
            "\nBaseline [structarmed-baseline.php]: 2 violations filtered out, 0 no longer found.\n\n",
            $output
        );
        $this->assertStringNotContainsString('Warning:', $output);
        $this->assertStringContainsString('No violations found', $outputWithoutParameter);
        $this->assertStringNotContainsString('Baseline [', $outputWithoutParameter);
        $this->assertStringEndsWith(
            "\nWarning: Baseline [structarmed-baseline.php]: 1 violation filtered out, 1 no longer found.\n\n",
            $this->withoutAnsi($staleOutput)
        );
        // A missing baseline has nothing to tell beyond its own warning.
        $this->assertStringContainsString(
            "\nWarning: Baseline file [structarmed-baseline.php] does not exist",
            $this->withoutAnsi($missingOutput)
        );
        $this->assertStringEndsWith("without baseline filtering.\n\n", $missingOutput);
        $this->assertStringNotContainsString('filtered out', $missingOutput);
        $this->assertStringEndsWith("\nBaseline: not configured.\n\n", $notConfiguredOutput);
    }

    public function testBaselineInfoIsEnabledOnlyByTrueBaselineInfoParameter(): void
    {
        $this->assertTrue($this->isBaselineInfoEnabled(['baseline-info' => 'true']));
        $this->assertTrue($this->isBaselineInfoEnabled(['baseline-info' => '1']));
        $this->assertFalse($this->isBaselineInfoEnabled([]));
        $this->assertFalse($this->isBaselineInfoEnabled(['baseline-info' => 'false']));
        $this->assertFalse($this->isBaselineInfoEnabled(['baseline-info' => 'not-a-boolean']));
    }

    public function testProgressIsEnabledWhenParameterIsMissing(): void
    {
        $this->assertTrue($this->isProgressEnabled($this->configuration(), []));
    }

    public function testProgressIsDisabledByPhpUnitNoProgressFlag(): void
    {
        $this->assertFalse($this->isProgressEnabled($this->configuration(noProgress: true), []));
        $this->assertFalse($this->isProgressEnabled($this->configuration(noProgress: true), ['progress' => 'true']));
    }

    public function testCacheIsEnabledWhenNoCacheParameterIsMissingOrNotTrue(): void
    {
        $this->assertFalse($this->isCacheDisabled([]));
        $this->assertFalse($this->isCacheDisabled(['no-cache' => 'false']));
        $this->assertFalse($this->isCacheDisabled(['no-cache' => 'not-a-boolean']));
    }

    public function testCacheIsDisabledByNoCacheParameter(): void
    {
        $this->assertTrue($this->isCacheDisabled(['no-cache' => 'true']));
    }

    /** @param array<string, string> $parameters */
    private function isBaselineInfoEnabled(array $parameters): bool
    {
        $result = (new ReflectionClass(StructArmedExtension::class))->getMethod('isBaselineInfoEnabled')->invoke(
            new StructArmedExtension(),
            ParameterCollection::fromArray($parameters)
        );
        $this->assertIsBool($result);

        return $result;
    }

    /** @param array<string, string> $parameters */
    private function isCacheDisabled(array $parameters): bool
    {
        $result = (new ReflectionClass(StructArmedExtension::class))->getMethod('isCacheDisabled')->invoke(
            new StructArmedExtension(),
            ParameterCollection::fromArray($parameters)
        );
        $this->assertIsBool($result);

        return $result;
    }

    /** @param array<string, string> $parameters */
    private function isProgressEnabled(Configuration $configuration, array $parameters): bool
    {
        $result = (new ReflectionClass(StructArmedExtension::class))->getMethod('isProgressEnabled')->invoke(
            new StructArmedExtension(),
            $configuration,
            ParameterCollection::fromArray($parameters)
        );
        $this->assertIsBool($result);

        return $result;
    }

    private function configuration(bool $noProgress = false): Configuration
    {
        $reflectionClass = new ReflectionClass(Configuration::class);
        $configuration   = $reflectionClass->newInstanceWithoutConstructor();
        $reflectionClass->getProperty('noProgress')->setValue($configuration, $noProgress);

        return $configuration;
    }

    private function parameters(
        ?string $configPath = null,
        bool $noCache = false,
        bool $baselineInfo = false
    ): ParameterCollection {
        $parameters = ['progress' => 'false'];

        if ($configPath !== null) {
            $parameters['config'] = $configPath;
        }

        if ($noCache) {
            $parameters['no-cache'] = 'true';
        }

        if ($baselineInfo) {
            $parameters['baseline-info'] = 'true';
        }

        return ParameterCollection::fromArray($parameters);
    }

    private function withoutAnsi(string $value): string
    {
        return (string) preg_replace('/\e\[[0-9;]*m/', '', $value);
    }

    /**
     * The output of one bootstrap run, to compare several runs within a test.
     */
    private function bootstrapOutput(ParameterCollection $parameterCollection): string
    {
        ob_start();

        try {
            (new StructArmedExtension())->bootstrap($this->configuration(), new Facade(), $parameterCollection);
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    /**
     * A project with one final class, a must be final rule, and
     * a "custom-cache" cache directory, relative to the project.
     */
    private function makeProjectWithCacheDirectory(string $prefix): string
    {
        $basePath = $this->makeTemporaryDirectory($prefix);
        mkdir($basePath . '/src');
        file_put_contents($basePath . '/src/Foo.php', <<<'PHP'
            <?php

            final class Foo
            {
            }

            PHP);
        file_put_contents($basePath . '/structarmed.php', <<<'PHP'
            <?php

            use Boundwize\StructArmed\Architecture;
            use Boundwize\StructArmed\Rule\Rules\Class_\MustBeFinalRule;

            return Architecture::define()
                ->layer('App', 'src/')
                ->rule('app.must_be_final', new MustBeFinalRule('App'))
                ->cacheDirectory('custom-cache');

            PHP);

        return $basePath;
    }

    /**
     * @return array<string, string>
     */
    private function directoryContents(string $directory): array
    {
        $contents = [];

        foreach (glob($directory . '/*.json') ?: [] as $file) {
            $contents[$file] = (string) file_get_contents($file);
        }

        return $contents;
    }

    private function cacheDirectoryCall(): string
    {
        return '    ->cacheDirectory('
            . var_export($this->makeTemporaryDirectory('structarmed-extension-cache'), true)
            . ')';
    }

    private function writeConfig(string $body): string
    {
        $path = $this->makeTemporaryFile('structarmed-extension');
        file_put_contents($path, "<?php\n\n" . $body . "\n");

        return $path;
    }

    private function makeTempProjectConfig(string $body): string
    {
        $basePath = $this->makeTemporaryDirectory('structarmed-extension');
        file_put_contents($basePath . '/structarmed.php', "<?php\n\n" . $body . "\n");

        return $basePath;
    }
}
