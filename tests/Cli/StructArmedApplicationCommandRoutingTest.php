<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Cli;

use Boundwize\StructArmed\Cli\AnalyseCommand;
use Boundwize\StructArmed\Cli\ClearCacheCommand;
use Boundwize\StructArmed\Cli\InitCommand;
use Boundwize\StructArmed\Cli\StructArmedApplication;
use Boundwize\StructArmed\Cli\Usage;
use Boundwize\StructArmed\Version;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function ob_get_clean;
use function ob_start;
use function sprintf;

#[CoversClass(AnalyseCommand::class)]
#[CoversClass(ClearCacheCommand::class)]
#[CoversClass(InitCommand::class)]
#[CoversClass(StructArmedApplication::class)]
#[CoversClass(Usage::class)]
#[CoversClass(Version::class)]
final class StructArmedApplicationCommandRoutingTest extends TestCase
{
    private const BASE_PATH = '/structarmed-test-project';

    public function testApplicationPrintsUsageWithoutCommand(): void
    {
        [$exitCode, $output] = $this->runApplication(['structarmed']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('structarmed --version', $output);
        $this->assertStringContainsString('structarmed init', $output);
        $this->assertStringContainsString('structarmed analyse|analyze', $output);
    }

    public function testApplicationPrintsVersion(): void
    {
        [$exitCode, $output] = $this->runApplication(['structarmed', '--version']);

        $this->assertSame(0, $exitCode);
        $this->assertSame(
            sprintf("StructArmed %s\n", Version::current()),
            $output
        );
    }

    public function testApplicationRejectsUnknownCommand(): void
    {
        [$exitCode, $output] = $this->runApplication(['structarmed', 'unknown']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unknown command: unknown', $output);
    }

    public function testApplicationReportsInvalidClearCacheConfig(): void
    {
        [$exitCode, $output] = $this->runApplication(
            ['structarmed', '--clear-cache', '--config=' . self::BASE_PATH . '/missing.php'],
            self::BASE_PATH
        );

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('StructArmed config file not found', $output);
    }

    public function testApplicationRejectsUnknownClearCacheOption(): void
    {
        [$exitCode, $output] = $this->runApplication(['structarmed', '--clear-cache', '--bad-option']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unknown option: --bad-option', $output);
    }

    public function testInitCommandRejectsUnknownOption(): void
    {
        [$exitCode, $output] = $this->runApplication(['structarmed', 'init', '--bad-option'], self::BASE_PATH);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unknown option: --bad-option', $output);
    }

    public function testInitCommandRejectsInvalidPreset(): void
    {
        [$exitCode, $output] = $this->runApplication(['structarmed', 'init', '--preset=unknown'], self::BASE_PATH);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Invalid preset: unknown', $output);
    }

    public function testAnalyseCommandRejectsInvalidReportType(): void
    {
        [$exitCode, $output] = $this->runApplication(['structarmed', 'analyse', '--report=xml'], self::BASE_PATH);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Invalid report type: xml', $output);
    }

    public function testAnalyseCommandRejectsUnknownOption(): void
    {
        [$exitCode, $output] = $this->runApplication(['structarmed', 'analyse', '--bad-option'], self::BASE_PATH);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unknown option: --bad-option', $output);
    }

    public function testAnalyseCommandRejectsMissingScanPath(): void
    {
        [$exitCode, $output] = $this->runApplication(['structarmed', 'analyse', 'missing'], self::BASE_PATH);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Error: path [missing] not found.', $output);
    }

    public function testAnalyseCommandAcceptsAbsoluteScanPath(): void
    {
        [$exitCode, $output] = $this->runApplication(
            [
                'structarmed',
                'analyse',
                __DIR__,
                '--config=' . self::BASE_PATH . '/missing.php',
            ],
            self::BASE_PATH
        );

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('StructArmed config file not found', $output);
    }

    public function testAnalyseCommandReportsMissingConfig(): void
    {
        [$exitCode, $output] = $this->runApplication(['structarmed', 'analyse'], self::BASE_PATH);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Could not find a structarmed.php config file', $output);
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
}
