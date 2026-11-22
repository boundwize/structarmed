<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Cli;

use Boundwize\StructArmed\Cli\AppendCommand;
use Boundwize\StructArmed\Cli\StructArmedApplication;
use Boundwize\StructArmed\Cli\Usage;
use Boundwize\StructArmed\Config\ConfigLoader;
use Boundwize\StructArmed\Preset\Preset;
use PhpToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function mkdir;
use function ob_end_clean;
use function ob_get_contents;
use function ob_start;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

use const TOKEN_PARSE;

#[CoversClass(AppendCommand::class)]
#[CoversClass(ConfigLoader::class)]
#[CoversClass(StructArmedApplication::class)]
#[CoversClass(Usage::class)]
final class AppendCommandTest extends TestCase
{
    private const BASE_PATH = '/structarmed-test-project';

    public function testAppendCommandRejectsUnknownOption(): void
    {
        [$exitCode, $output] = $this->runApplication(['structarmed', 'append', '--bad-option'], self::BASE_PATH);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unknown option: --bad-option', $output);
    }

    public function testAppendCommandRejectsInvalidPreset(): void
    {
        [$exitCode, $output] = $this->runApplication(['structarmed', 'append', '--preset=unknown'], self::BASE_PATH);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Invalid preset: unknown', $output);
        $this->assertStringContainsString('structarmed append --preset=', $output);
    }

    public function testAppendCommandRequiresPreset(): void
    {
        [$exitCode, $output] = $this->runApplication(['structarmed', 'append'], self::BASE_PATH);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Invalid preset: ', $output);
    }

    public function testAppendCommandReportsMissingConfig(): void
    {
        [$exitCode, $output] = $this->runApplication(
            ['structarmed', 'append', '--preset', 'codequality'],
            self::BASE_PATH
        );

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Error: Could not find a structarmed.php config file', $output);
    }

    public function testAppendCommandUpdatesConfig(): void
    {
        foreach ($this->appendCases() as $description => [$fileName, $preset, $source, $expectedOutput, $expected]) {
            $basePath   = sys_get_temp_dir() . '/structarmed-append-' . bin2hex(random_bytes(6));
            $configFile = $basePath . '/' . $fileName;

            mkdir($basePath);
            file_put_contents($configFile, $source);

            try {
                [$exitCode, $output] = $this->runApplication(
                    ['structarmed', 'append', '--preset=' . $preset],
                    $basePath
                );

                $this->assertSame($expected === $source && $source === '' ? 1 : 0, $exitCode, $description);
                $this->assertSame($expectedOutput, $output, $description);
                $this->assertSame($expected, file_get_contents($configFile), $description);
                // TOKEN_PARSE makes PHP itself throw on a config that is no longer valid syntax
                $this->assertSame($expected, implode('', PhpToken::tokenize($expected, TOKEN_PARSE)), $description);
            } finally {
                unlink($configFile);
                rmdir($basePath);
            }
        }
    }

    public function testAppendCommandIsIdempotent(): void
    {
        $basePath   = sys_get_temp_dir() . '/structarmed-append-' . bin2hex(random_bytes(6));
        $configFile = $basePath . '/structarmed.php';

        mkdir($basePath);
        file_put_contents($configFile, <<<'PHP'
            <?php

            use Boundwize\StructArmed\Architecture;
            use Boundwize\StructArmed\Preset\Preset;

            // ->withPreset(Preset::ENUM())

            return Architecture::define()
                ->withPreset(Preset::PSR4());

            PHP);

        try {
            [$exitCode, $output] = $this->runApplication(['structarmed', 'append', '--preset=all'], $basePath);
            $appended            = (string) file_get_contents($configFile);

            $this->assertSame(0, $exitCode);
            $this->assertStringStartsWith('Appended Preset::PSR1(), Preset::PSR12(), ', $output);
            $this->assertStringEndsWith(
                <<<'PHP'
                    ->withPreset(Preset::PSR4())
                    ->withPreset(Preset::PSR1())
                    ->withPreset(Preset::PSR12())
                    ->withPreset(Preset::PER())
                    ->withPreset(Preset::PSR15())
                    ->withPreset(Preset::DDD())
                    ->withPreset(Preset::MVC())
                    ->withPreset(Preset::YAGNI())
                    ->withPreset(Preset::CODEQUALITY())
                    ->withPreset(Preset::STABILITY())
                    ->withPreset(Preset::ENUM());

                PHP,
                $appended
            );

            foreach (['all', 'enum'] as $preset) {
                [$exitCode, $output] = $this->runApplication(
                    ['structarmed', 'append', '--preset=' . $preset],
                    $basePath
                );

                $this->assertSame(0, $exitCode, $preset);
                $this->assertSame('structarmed.php already has the ' . $preset . " preset.\n", $output);
                $this->assertSame($appended, file_get_contents($configFile), $preset);
            }
        } finally {
            unlink($configFile);
            rmdir($basePath);
        }
    }

    public function testAppendCommandLeavesConfigItCannotAppendToUntouched(): void
    {
        foreach ($this->rejectedCases() as $description => [$source, $expectedOutput]) {
            $basePath   = sys_get_temp_dir() . '/structarmed-append-' . bin2hex(random_bytes(6));
            $configFile = $basePath . '/structarmed.php';

            mkdir($basePath);
            file_put_contents($configFile, $source);

            try {
                [$exitCode, $output] = $this->runApplication(
                    ['structarmed', 'append', '--preset=enum'],
                    $basePath
                );

                $this->assertSame(1, $exitCode, $description);
                $this->assertSame($expectedOutput, $output, $description);
                $this->assertSame($source, file_get_contents($configFile), $description);
            } finally {
                unlink($configFile);
                rmdir($basePath);
            }
        }
    }

    public function testAppendCommandSkipsPresetsOfImportedConfigs(): void
    {
        $basePath = sys_get_temp_dir() . '/structarmed-append-' . bin2hex(random_bytes(6));
        $sources  = [
            'structarmed.php' => <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;

                $architecture = Architecture::define()
                    ->import(__DIR__ . '/structarmed.dist.php')
                    ->import(__DIR__ . '/module/missing.php')
                    ->import(__DIR__ . '/broken.php');

                foreach (glob(__DIR__ . '/module/*/structarmed.php') ?: [] as $module) {
                    $architecture->import($module);
                }

                return $architecture
                    ->baseline(__DIR__ . '/structarmed-baseline.php')
                ;

                PHP,
            // imports its importer back, which must not loop
            'structarmed.dist.php' => <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;
                use Boundwize\StructArmed\Preset\Preset as P;

                return static function (Architecture $architecture): void {
                    $architecture
                        ->withPresets(P::PSR4(), P::DDD())
                        ->import(__DIR__ . '/structarmed.php');
                };

                PHP,
            'broken.php'           => <<<'PHP'
                <?php

                return static function (

                PHP,
        ];

        mkdir($basePath);

        foreach ($sources as $fileName => $source) {
            file_put_contents($basePath . '/' . $fileName, $source);
        }

        try {
            [$exitCode, $output] = $this->runApplication(['structarmed', 'append', '--preset=ddd'], $basePath);

            $this->assertSame(0, $exitCode);
            $this->assertSame("structarmed.php already has the ddd preset.\n", $output);
            $this->assertSame($sources['structarmed.php'], file_get_contents($basePath . '/structarmed.php'));

            [$exitCode, $output] = $this->runApplication(['structarmed', 'append', '--preset=enum'], $basePath);

            $this->assertSame(0, $exitCode);
            $this->assertSame('Appended \\' . Preset::class . '::ENUM() to structarmed.php' . "\n", $output);
            $this->assertStringEndsWith(
                <<<'PHP'
                    return $architecture
                        ->baseline(__DIR__ . '/structarmed-baseline.php')
                        ->withPreset(\Boundwize\StructArmed\Preset\Preset::ENUM())
                    ;

                    PHP,
                (string) file_get_contents($basePath . '/structarmed.php')
            );
            $this->assertSame(
                $sources['structarmed.dist.php'],
                file_get_contents($basePath . '/structarmed.dist.php')
            );
        } finally {
            foreach ($sources as $fileName => $source) {
                unlink($basePath . '/' . $fileName);
            }

            rmdir($basePath);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    private function rejectedCases(): iterable
    {
        yield 'invalid PHP syntax' => [
            <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;
                use Boundwize\StructArmed\Preset\Preset;

                return Architecture::define()
                    ->withPreset(Preset::PSR4();

                PHP,
            "Error: could not parse structarmed.php: Syntax error, unexpected ';', expecting ')' on line 7.\n",
        ];

        yield 'a closure without a parameter to call' => [
            <<<'PHP'
                <?php

                return static function (): void {
                };

                PHP,
            "Error: could not find a statement to append to in structarmed.php.\n",
        ];

        yield 'a closure that ends with a return of something else' => [
            <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;

                return static function (Architecture $architecture): void {
                    $architecture->layer('Domain', 'src/Domain/');

                    return;
                };

                PHP,
            "Error: could not find a statement to append to in structarmed.php.\n",
        ];
    }

    /**
     * @return iterable<string, array{string, string, string, string, string}>
     */
    private function appendCases(): iterable
    {
        yield 'appends to the config generated by init' => [
            'structarmed.php',
            'codequality',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                use Boundwize\StructArmed\Architecture;
                use Boundwize\StructArmed\Preset\Preset;

                return Architecture::define()
                    ->withPreset(Preset::PSR4());

                PHP,
            "Appended Preset::CODEQUALITY() to structarmed.php\n",
            <<<'PHP'
                <?php

                declare(strict_types=1);

                use Boundwize\StructArmed\Architecture;
                use Boundwize\StructArmed\Preset\Preset;

                return Architecture::define()
                    ->withPreset(Preset::PSR4())
                    ->withPreset(Preset::CODEQUALITY());

                PHP,
        ];

        yield 'keeps a preset that is already enabled' => [
            'structarmed.php',
            'PSR4',
            <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;
                use Boundwize\StructArmed\Preset\Preset;

                return Architecture::define()
                    ->withPreset(Preset::PSR4(sourcePaths: ['src/']));

                PHP,
            "structarmed.php already has the psr4 preset.\n",
            <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;
                use Boundwize\StructArmed\Preset\Preset;

                return Architecture::define()
                    ->withPreset(Preset::PSR4(sourcePaths: ['src/']));

                PHP,
        ];

        yield 'all appends only the missing presets, after the last call of the chain' => [
            'structarmed.dist.php',
            'all',
            <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;
                use Boundwize\StructArmed\Preset\Preset;

                $architecture = Architecture::define()
                    ->withPresets(
                        Preset::PSR4(),
                        Preset::PSR1(),
                        Preset::PSR12(),
                        Preset::PER(),
                        Preset::PSR15(),
                        Preset::DDD(),
                        Preset::MVC(),
                        Preset::YAGNI(),
                    );

                return $architecture
                    ->skipPath('src/Legacy/') // legacy code
                ;

                PHP,
            'Appended Preset::CODEQUALITY(), Preset::STABILITY(), Preset::ENUM() to structarmed.dist.php' . "\n",
            <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;
                use Boundwize\StructArmed\Preset\Preset;

                $architecture = Architecture::define()
                    ->withPresets(
                        Preset::PSR4(),
                        Preset::PSR1(),
                        Preset::PSR12(),
                        Preset::PER(),
                        Preset::PSR15(),
                        Preset::DDD(),
                        Preset::MVC(),
                        Preset::YAGNI(),
                    );

                return $architecture
                    ->skipPath('src/Legacy/') // legacy code
                    ->withPreset(Preset::CODEQUALITY())
                    ->withPreset(Preset::STABILITY())
                    ->withPreset(Preset::ENUM())
                ;

                PHP,
        ];

        yield 'uses the fully qualified class name without a Preset import' => [
            'structarmed.php',
            'enum',
            <<<'PHP'
                <?php

                return \Boundwize\StructArmed\Architecture::define();

                PHP,
            'Appended \\' . Preset::class . '::ENUM() to structarmed.php' . "\n",
            <<<'PHP'
                <?php

                return \Boundwize\StructArmed\Architecture::define()
                    ->withPreset(\Boundwize\StructArmed\Preset\Preset::ENUM());

                PHP,
        ];

        yield 'reports a config without a statement' => [
            'structarmed.php',
            'enum',
            '',
            "Error: could not find a statement to append to in structarmed.php.\n",
            '',
        ];

        yield 'appends a preset that only a comment mentions' => [
            'structarmed.php',
            'enum',
            <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;
                use Boundwize\StructArmed\Preset\Preset;

                // ->withPreset(Preset::ENUM())

                return Architecture::define()
                    ->withPreset(Preset::PSR4());

                PHP,
            "Appended Preset::ENUM() to structarmed.php\n",
            <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;
                use Boundwize\StructArmed\Preset\Preset;

                // ->withPreset(Preset::ENUM())

                return Architecture::define()
                    ->withPreset(Preset::PSR4())
                    ->withPreset(Preset::ENUM());

                PHP,
        ];

        yield 'appends a preset that only a string mentions' => [
            'structarmed.php',
            'enum',
            <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;
                use Boundwize\StructArmed\Preset\Preset;

                $todo = 'enable ->withPreset(Preset::ENUM()) once the enums are final';

                return Architecture::define()
                    ->withPreset(Preset::PSR4());

                PHP,
            "Appended Preset::ENUM() to structarmed.php\n",
            <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;
                use Boundwize\StructArmed\Preset\Preset;

                $todo = 'enable ->withPreset(Preset::ENUM()) once the enums are final';

                return Architecture::define()
                    ->withPreset(Preset::PSR4())
                    ->withPreset(Preset::ENUM());

                PHP,
        ];

        yield 'appends a preset whose factory is not applied to the architecture' => [
            'structarmed.php',
            'enum',
            <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;
                use Boundwize\StructArmed\Preset\Preset;

                $unused = Preset::ENUM();

                return Architecture::define()
                    ->withPreset(Preset::PSR4());

                PHP,
            "Appended Preset::ENUM() to structarmed.php\n",
            <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;
                use Boundwize\StructArmed\Preset\Preset;

                $unused = Preset::ENUM();

                return Architecture::define()
                    ->withPreset(Preset::PSR4())
                    ->withPreset(Preset::ENUM());

                PHP,
        ];

        yield 'keeps a preset that is enabled through withPresets()' => [
            'structarmed.php',
            'ddd',
            <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;
                use Boundwize\StructArmed\Preset\Preset;

                return Architecture::define()
                    ->withPresets(Preset::PSR4(), Preset::DDD());

                PHP,
            "structarmed.php already has the ddd preset.\n",
            <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;
                use Boundwize\StructArmed\Preset\Preset;

                return Architecture::define()
                    ->withPresets(Preset::PSR4(), Preset::DDD());

                PHP,
        ];

        yield 'keeps a preset that reaches the architecture through a variable' => [
            'structarmed.php',
            'enum',
            <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;
                use Boundwize\StructArmed\Preset\Preset;

                $presets = [Preset::PSR4(), Preset::ENUM()];

                return Architecture::define()
                    ->withPresets(...$presets);

                PHP,
            "structarmed.php already has the enum preset.\n",
            <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;
                use Boundwize\StructArmed\Preset\Preset;

                $presets = [Preset::PSR4(), Preset::ENUM()];

                return Architecture::define()
                    ->withPresets(...$presets);

                PHP,
        ];

        yield 'all skips aliased and fully qualified presets, and appends with the alias' => [
            'structarmed.php',
            'all',
            <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;
                use Boundwize\StructArmed\Preset\Preset as P;

                return Architecture::define()
                    ->withPresets(P::PSR4(), P::PSR1(), P::PSR12(), P::PER(), P::PSR15(), P::DDD(), P::MVC())
                    ->withPreset(\Boundwize\StructArmed\Preset\Preset::YAGNI())
                    ->withPreset(p::codequality());

                PHP,
            "Appended P::STABILITY(), P::ENUM() to structarmed.php\n",
            <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;
                use Boundwize\StructArmed\Preset\Preset as P;

                return Architecture::define()
                    ->withPresets(P::PSR4(), P::PSR1(), P::PSR12(), P::PER(), P::PSR15(), P::DDD(), P::MVC())
                    ->withPreset(\Boundwize\StructArmed\Preset\Preset::YAGNI())
                    ->withPreset(p::codequality())
                    ->withPreset(P::STABILITY())
                    ->withPreset(P::ENUM());

                PHP,
        ];

        yield 'uses the fully qualified class name when the Preset import is commented out' => [
            'structarmed.php',
            'enum',
            <<<'PHP'
                <?php

                namespace Acme\Config;

                use Boundwize\StructArmed\Architecture;
                // use Boundwize\StructArmed\Preset\Preset;

                return Architecture::define();

                PHP,
            'Appended \\' . Preset::class . '::ENUM() to structarmed.php' . "\n",
            <<<'PHP'
                <?php

                namespace Acme\Config;

                use Boundwize\StructArmed\Architecture;
                // use Boundwize\StructArmed\Preset\Preset;

                return Architecture::define()
                    ->withPreset(\Boundwize\StructArmed\Preset\Preset::ENUM());

                PHP,
        ];

        yield 'appends to the returned chain, not to a later statement' => [
            'structarmed.php',
            'enum',
            <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;
                use Boundwize\StructArmed\Preset\Preset;

                return Architecture::define()
                    ->skip(legacyPaths());

                function legacyPaths(): array
                {
                    return ['src/Legacy/'];
                }

                PHP,
            "Appended Preset::ENUM() to structarmed.php\n",
            <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;
                use Boundwize\StructArmed\Preset\Preset;

                return Architecture::define()
                    ->skip(legacyPaths())
                    ->withPreset(Preset::ENUM());

                function legacyPaths(): array
                {
                    return ['src/Legacy/'];
                }

                PHP,
        ];

        yield 'keeps comments, spacing and a missing trailing newline as they are' => [
            'structarmed.php',
            'enum',
            <<<'PHP'
                <?php
                use Boundwize\StructArmed\Architecture ;
                use Boundwize\StructArmed\Preset\Preset;


                return   Architecture::define()
                  ->withPreset( Preset::PSR4() )   /* base */   ;   # done
                PHP,
            "Appended Preset::ENUM() to structarmed.php\n",
            <<<'PHP'
                <?php
                use Boundwize\StructArmed\Architecture ;
                use Boundwize\StructArmed\Preset\Preset;


                return   Architecture::define()
                  ->withPreset( Preset::PSR4() )   /* base */
                    ->withPreset(Preset::ENUM())   ;   # done
                PHP,
        ];

        yield 'appends inside the closure of a config written for import()' => [
            'structarmed.dist.php',
            'all',
            <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;
                use Boundwize\StructArmed\Preset\Preset;

                return static function (Architecture $architecture): void {
                    $architecture
                        ->withPresets(
                            Preset::PSR4(),
                            Preset::PSR1(),
                            Preset::PSR12(),
                            Preset::PER(),
                            Preset::PSR15(),
                            Preset::DDD(),
                            Preset::MVC(),
                            Preset::YAGNI(),
                            Preset::CODEQUALITY(),
                        )
                        // the kernel is the composition root
                        ->layer('Kernel', 'src/Application/Kernel/')
                        ->baseline(__DIR__ . '/structarmed-baseline.dist.php')
                    ;
                };

                PHP,
            "Appended Preset::STABILITY(), Preset::ENUM() to structarmed.dist.php\n",
            <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;
                use Boundwize\StructArmed\Preset\Preset;

                return static function (Architecture $architecture): void {
                    $architecture
                        ->withPresets(
                            Preset::PSR4(),
                            Preset::PSR1(),
                            Preset::PSR12(),
                            Preset::PER(),
                            Preset::PSR15(),
                            Preset::DDD(),
                            Preset::MVC(),
                            Preset::YAGNI(),
                            Preset::CODEQUALITY(),
                        )
                        // the kernel is the composition root
                        ->layer('Kernel', 'src/Application/Kernel/')
                        ->baseline(__DIR__ . '/structarmed-baseline.dist.php')
                        ->withPreset(Preset::STABILITY())
                        ->withPreset(Preset::ENUM())
                    ;
                };

                PHP,
        ];

        yield 'adds a statement to a closure that does not end with a chain' => [
            'structarmed.php',
            'enum',
            <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;

                return static function (Architecture $architecture): void {
                    $layers = ['Domain' => 'src/Domain/'];

                    foreach ($layers as $name => $path) {
                        $architecture->layer($name, $path);
                    }
                };

                PHP,
            'Appended \\' . Preset::class . '::ENUM() to structarmed.php' . "\n",
            <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;

                return static function (Architecture $architecture): void {
                    $layers = ['Domain' => 'src/Domain/'];

                    foreach ($layers as $name => $path) {
                        $architecture->layer($name, $path);
                    }

                    $architecture->withPreset(\Boundwize\StructArmed\Preset\Preset::ENUM());
                };

                PHP,
        ];

        yield 'adds a statement to a closure that ends with a call on another object' => [
            'structarmed.php',
            'enum',
            <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;
                use Boundwize\StructArmed\Preset\Preset;

                return static function (Architecture $architecture) use ($logger): void {
                    $architecture->layer('Domain', 'src/Domain/');

                    $logger->info('Configuration loaded');
                };

                PHP,
            "Appended Preset::ENUM() to structarmed.php\n",
            <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;
                use Boundwize\StructArmed\Preset\Preset;

                return static function (Architecture $architecture) use ($logger): void {
                    $architecture->layer('Domain', 'src/Domain/');

                    $logger->info('Configuration loaded');

                    $architecture->withPreset(Preset::ENUM());
                };

                PHP,
        ];

        yield 'continues the chain a closure returns' => [
            'structarmed.php',
            'enum',
            <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;
                use Boundwize\StructArmed\Preset\Preset;

                return static function (Architecture $architecture): Architecture {
                    return $architecture;
                };

                PHP,
            "Appended Preset::ENUM() to structarmed.php\n",
            <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;
                use Boundwize\StructArmed\Preset\Preset;

                return static function (Architecture $architecture): Architecture {
                    return $architecture
                        ->withPreset(Preset::ENUM());
                };

                PHP,
        ];

        yield 'adds one chained statement to an empty closure' => [
            'structarmed.php',
            'all',
            <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;
                use Boundwize\StructArmed\Preset\Preset;

                return static function (Architecture $config): void {
                };

                PHP,
            'Appended Preset::PSR4(), Preset::PSR1(), Preset::PSR12(), Preset::PER(), Preset::PSR15(), '
            . 'Preset::DDD(), Preset::MVC(), Preset::YAGNI(), Preset::CODEQUALITY(), Preset::STABILITY(), '
            . 'Preset::ENUM() to structarmed.php' . "\n",
            <<<'PHP'
                <?php

                use Boundwize\StructArmed\Architecture;
                use Boundwize\StructArmed\Preset\Preset;

                return static function (Architecture $config): void {
                    $config->withPreset(Preset::PSR4())
                        ->withPreset(Preset::PSR1())
                        ->withPreset(Preset::PSR12())
                        ->withPreset(Preset::PER())
                        ->withPreset(Preset::PSR15())
                        ->withPreset(Preset::DDD())
                        ->withPreset(Preset::MVC())
                        ->withPreset(Preset::YAGNI())
                        ->withPreset(Preset::CODEQUALITY())
                        ->withPreset(Preset::STABILITY())
                        ->withPreset(Preset::ENUM());
                };

                PHP,
        ];
    }

    /**
     * @param list<string> $argv
     * @return array{int, string}
     */
    private function runApplication(array $argv, string $basePath): array
    {
        ob_start();
        $exitCode = (new StructArmedApplication())->run($argv, $basePath);
        $output   = ob_get_contents();
        ob_end_clean();

        return [$exitCode, (string) $output];
    }
}
