<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Cli;

use Boundwize\StructArmed\Cli\ColorSupport;
use Boundwize\StructArmed\Cli\DiagnoseRulesetCommand;
use Boundwize\StructArmed\Cli\StructArmedApplication;
use Boundwize\StructArmed\Cli\Usage;
use Boundwize\StructArmed\Config\ConfigLoader;
use Boundwize\StructArmed\Ruleset\RulesetDiagnoser;
use Boundwize\StructArmed\Ruleset\RulesetDiagnostic;
use Boundwize\StructArmed\Util\Path;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function array_values;
use function explode;
use function getenv;
use function ob_end_clean;
use function ob_get_contents;
use function ob_start;
use function putenv;
use function sprintf;

/**
 * The fixture layers point at directories that do not exist: the command only
 * reads the configured ruleset, so it never looks for them.
 */
#[CoversClass(DiagnoseRulesetCommand::class)]
#[CoversClass(StructArmedApplication::class)]
#[UsesClass(ColorSupport::class)]
#[UsesClass(ConfigLoader::class)]
#[UsesClass(RulesetDiagnoser::class)]
#[UsesClass(RulesetDiagnostic::class)]
#[UsesClass(Usage::class)]
final class DiagnoseRulesetCommandTest extends TestCase
{
    private const WORKING_DIRECTORY = '/structarmed-test-project';

    private const COLOR_ENVIRONMENT_VARIABLES = ['NO_COLOR', 'FORCE_COLOR'];

    /** @var array<string, string|false> */
    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        foreach (self::COLOR_ENVIRONMENT_VARIABLES as $name) {
            $this->previousEnvironment[$name] = getenv($name);
        }

        putenv('NO_COLOR=1');
        putenv('FORCE_COLOR');
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnvironment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testReportsDependencyAlreadyInheritedThroughPlusEntry(): void
    {
        [$exitCode, $output] = $this->runCommand('--config=' . self::fixture('structarmed.php'));

        // Warnings are advisory: the command passes.
        $this->assertSame(0, $exitCode);
        $this->assertSame(
            [
                'StructArmed Ruleset Diagnostics',
                '===============================',
                'Configuration: ' . $this->configurationPath('structarmed.php'),
                '',
                '+---------+----------+------------+------------------------------------+'
                    . '-------------------------------------------------------------+',
                '| Level   | Layer    | Dependency | Issue                              |'
                    . ' Fix                                                         |',
                '+---------+----------+------------+------------------------------------+'
                    . '-------------------------------------------------------------+',
                "| WARNING | Analyser | Composer   | Already inherited through '+Cache' |"
                    . " Remove 'Composer' from the dependencies of layer 'Analyser' |",
                '+---------+----------+------------+------------------------------------+'
                    . '-------------------------------------------------------------+',
                '1 warning, 0 errors',
                '',
            ],
            explode("\n", $output)
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rulesetWithoutIssuesProvider(): iterable
    {
        yield 'dependency reachable through a plain entry' => ['clean.php'];
        yield 'no ruleset' => ['no-ruleset.php'];
    }

    #[DataProvider('rulesetWithoutIssuesProvider')]
    public function testReportsNoIssues(string $fixture): void
    {
        [$exitCode, $output] = $this->runCommand('--config=' . self::fixture($fixture));

        $this->assertSame(0, $exitCode);
        $this->assertSame(
            sprintf(
                <<<'TXT'
                StructArmed Ruleset Diagnostics
                ===============================
                Configuration: %s

                No issues found.

                TXT,
                $this->configurationPath($fixture)
            ),
            $output
        );
    }

    public function testFailsOnUndefinedLayer(): void
    {
        [$exitCode, $output] = $this->runCommand('--config=' . self::fixture('issues.php'));

        $this->assertSame(1, $exitCode);
        $this->assertSame(
            [
                'StructArmed Ruleset Diagnostics',
                '===============================',
                'Configuration: ' . $this->configurationPath('issues.php'),
                '',
                '+---------+------------+------------+----------------------------+'
                    . '--------------------------------------------------------------------------------------------+',
                '| Level   | Layer      | Dependency | Issue                      |'
                    . ' Fix                                                                                        |',
                '+---------+------------+------------+----------------------------+'
                    . '--------------------------------------------------------------------------------------------+',
                '| WARNING | Controller | Service    | Listed more than once      |'
                    . " Keep a single 'Service' in the dependencies of layer 'Controller'                          |",
                '| WARNING | Service    | Service    | Same layer, always allowed |'
                    . " Remove 'Service' from the dependencies of layer 'Service'                                  |",
                '| ERROR   | Service    | Repository | Undefined layer            |'
                    . " Define layer 'Repository', or remove 'Repository' from the dependencies of layer 'Service' |",
                '+---------+------------+------------+----------------------------+'
                    . '--------------------------------------------------------------------------------------------+',
                '2 warnings, 1 error',
                '',
            ],
            explode("\n", $output)
        );
    }

    public function testColoursSeverityLevels(): void
    {
        putenv('NO_COLOR');
        putenv('FORCE_COLOR=1');

        [, $output] = $this->runCommand('--config=' . self::fixture('issues.php'));

        // The padding of the level column is part of the coloured cell, so the columns stay aligned.
        $this->assertStringContainsString("| \033[33mWARNING\033[0m | Service    | Service    |", $output);
        $this->assertStringContainsString("| \033[91mERROR  \033[0m | Service    | Repository |", $output);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function basePathOptionProvider(): iterable
    {
        yield 'long option' => [['diagnose-ruleset', '--basepath=' . self::fixture('')]];
        yield 'short option' => [['diagnose-ruleset', '-d', self::fixture('')]];
        yield 'before the command' => [['--basepath', self::fixture(''), 'diagnose-ruleset']];
    }

    /**
     * @param list<string> $arguments
     */
    #[DataProvider('basePathOptionProvider')]
    public function testDiscoversConfigInBasePath(array $arguments): void
    {
        [$exitCode, $output] = $this->runApplication($arguments);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(
            'Configuration: ' . $this->configurationPath('structarmed.php') . "\n",
            $output
        );
        $this->assertStringEndsWith("\n1 warning, 0 errors\n", $output);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function invalidConfigurationProvider(): iterable
    {
        yield 'config returns no architecture' => [
            ['--config=' . self::fixture('invalid.php')],
            'must return an instance of',
        ];

        yield 'config file not found' => [
            ['--config=' . self::fixture('missing.php')],
            'StructArmed config file not found',
        ];

        yield 'no config to discover' => [
            [],
            'Could not find a structarmed.php config file',
        ];

        yield 'base path not found' => [
            ['--basepath=missing'],
            'Error: base path [missing] not found.',
        ];
    }

    /**
     * @param list<string> $arguments
     */
    #[DataProvider('invalidConfigurationProvider')]
    public function testFailsOnInvalidConfiguration(array $arguments, string $expectedError): void
    {
        [$exitCode, $output] = $this->runCommand(...$arguments);

        $this->assertSame(1, $exitCode);
        $this->assertStringStartsWith('Error: ', $output);
        $this->assertStringContainsString($expectedError, $output);
        $this->assertStringNotContainsString('StructArmed Ruleset Diagnostics', $output);
    }

    public function testRejectsUnknownOption(): void
    {
        [$exitCode, $output] = $this->runCommand('--fix');

        $this->assertSame(1, $exitCode);
        $this->assertSame("Unknown option: --fix\n\n" . Usage::render(), $output);
    }

    private static function fixture(string $file): string
    {
        return __DIR__ . '/../Fixtures/ruleset/' . $file;
    }

    private function configurationPath(string $file): string
    {
        return Path::normalise(self::fixture($file), canonicalise: true);
    }

    /**
     * @return array{int, string}
     */
    private function runCommand(string ...$options): array
    {
        return $this->runApplication(['diagnose-ruleset', ...array_values($options)]);
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string}
     */
    private function runApplication(array $arguments): array
    {
        ob_start();
        $exitCode = (new StructArmedApplication())->run(['structarmed', ...$arguments], self::WORKING_DIRECTORY);
        $output   = ob_get_contents();
        ob_end_clean();

        return [$exitCode, (string) $output];
    }
}
