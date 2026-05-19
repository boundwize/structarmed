<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\PHPUnit;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Baseline\BaselineFilter;
use Boundwize\StructArmed\Exception\ViolationsFoundException;
use Boundwize\StructArmed\PHPUnit\StructArmedExtension;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeFinalRule;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use ReflectionClass;
use RuntimeException;

use function chdir;
use function file_put_contents;
use function getcwd;
use function json_encode;
use function mkdir;

#[CoversClass(StructArmedExtension::class)]
#[CoversClass(BaselineFilter::class)]
final class StructArmedExtensionTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testBootstrapPrintsPassingReport(): void
    {
        $configPath = $this->writeConfig('return ' . Architecture::class . '::define();');

        $this->expectOutputRegex('/No violations found/');

        (new StructArmedExtension())->bootstrap(
            $this->configuration(),
            new Facade(),
            ParameterCollection::fromArray(['config' => $configPath])
        );
    }

    public function testBootstrapDiscoversConfigWhenParameterIsMissing(): void
    {
        $basePath     = $this->makeTempProjectConfig('return ' . Architecture::class . '::define();');
        $previousPath = getcwd();
        $this->assertIsString($previousPath);

        chdir($basePath);

        try {
            $this->expectOutputRegex('/No violations found/');

            (new StructArmedExtension())->bootstrap(
                $this->configuration(),
                new Facade(),
                ParameterCollection::fromArray([])
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
                ParameterCollection::fromArray(['config' => $configPath])
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
                ParameterCollection::fromArray(['config' => $configPath])
            );
        } finally {
            chdir($previousPath);
        }
    }

    public function testBootstrapThrowsWhenViolationsAreFound(): void
    {
        $configPath = $this->writeConfig(
            'return ' . Architecture::class . "::define()\n"
            . "    ->layer('Domain', 'tests/Fixtures/sample/src/Domain/')\n"
            . "    ->rule('must_be_final', new " . MustBeFinalRule::class . "('Domain'));"
        );

        $this->expectException(ViolationsFoundException::class);
        $this->expectExceptionMessage('StructArmed found');
        $this->expectOutputRegex('/Found \d+ violation/');

        (new StructArmedExtension())->bootstrap(
            $this->configuration(),
            new Facade(),
            ParameterCollection::fromArray(['config' => $configPath])
        );
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
            . "    ->baseline('structarmed-baseline.php');\n"
        );

        chdir($basePath);

        try {
            $this->expectOutputRegex('/No violations found/');

            (new StructArmedExtension())->bootstrap(
                $this->configuration(),
                new Facade(),
                ParameterCollection::fromArray(['config' => $configPath])
            );
        } finally {
            chdir($previousPath);
        }
    }

    public function testBootstrapPropagatesExceptionWhenBaselineFileMissing(): void
    {
        $configPath = $this->writeConfig(
            'return ' . Architecture::class . "::define()->baseline('missing-baseline.php');"
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Baseline file [missing-baseline.php] does not exist.');

        (new StructArmedExtension())->bootstrap(
            $this->configuration(),
            new Facade(),
            ParameterCollection::fromArray(['config' => $configPath])
        );
    }

    private function configuration(): Configuration
    {
        return (new ReflectionClass(Configuration::class))->newInstanceWithoutConstructor();
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
