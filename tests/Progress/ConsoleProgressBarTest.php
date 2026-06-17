<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Progress;

use Boundwize\StructArmed\Progress\ConsoleProgressBar;
use Boundwize\StructArmed\Tests\Support\InMemoryStreamTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function count;
use function explode;
use function getenv;
use function putenv;
use function trim;

#[CoversClass(ConsoleProgressBar::class)]
final class ConsoleProgressBarTest extends TestCase
{
    use InMemoryStreamTrait;

    public function testProgressBarRendersFinalState(): void
    {
        $stream = $this->openMemoryStream();

        $consoleProgressBar = new ConsoleProgressBar($stream, 10, false, true);
        $consoleProgressBar->start(2);
        $consoleProgressBar->advance('/tmp/Foo.php');
        $consoleProgressBar->advance('/tmp/Bar.php');
        $consoleProgressBar->finish();

        $output = $this->streamContents($stream);

        $this->assertStringContainsString('Analyzing [==========] 100% 2/2', $output);
        $this->assertStringNotContainsString('Foo.php', $output);
        $this->assertStringNotContainsString('Bar.php', $output);
    }

    public function testProgressBarCanRenderWithColor(): void
    {
        $stream = $this->openMemoryStream();

        $consoleProgressBar = new ConsoleProgressBar($stream, 10, true, true);
        $consoleProgressBar->start(1);
        $consoleProgressBar->advance('/tmp/Foo.php');
        $consoleProgressBar->finish();

        $output = $this->streamContents($stream);

        $this->assertStringContainsString("\033[36mAnalyzing\033[0m", $output);
        $this->assertStringContainsString("\033[32m==========\033[0m", $output);
        $this->assertStringContainsString('100% 1/1', $output);
    }

    public function testProgressBarCanDetectForcedColor(): void
    {
        $this->withEnvironment(
            noColor: null,
            forceColor: '1',
            callback: function (): void {
                $stream = $this->openMemoryStream();

                $consoleProgressBar = new ConsoleProgressBar($stream, 10, isTty: true);
                $consoleProgressBar->start(1);
                $consoleProgressBar->advance('/tmp/Foo.php');
                $consoleProgressBar->finish();

                $output = $this->streamContents($stream);

                $this->assertStringContainsString("\033[36mAnalyzing\033[0m", $output);
            }
        );
    }

    public function testNoColorEnvironmentDisablesForcedColor(): void
    {
        $this->withEnvironment(
            noColor: '1',
            forceColor: '1',
            callback: function (): void {
                $stream = $this->openMemoryStream();

                $consoleProgressBar = new ConsoleProgressBar($stream, 10, isTty: true);
                $consoleProgressBar->start(1);
                $consoleProgressBar->advance('/tmp/Foo.php');
                $consoleProgressBar->finish();

                $output = $this->streamContents($stream);

                $this->assertStringContainsString('Analyzing [==========] 100% 1/1', $output);
                $this->assertStringNotContainsString("\033[", $output);
            }
        );
    }

    public function testProgressBarFallsBackToStreamDetectionWithoutColorEnvironment(): void
    {
        $this->withEnvironment(
            noColor: null,
            forceColor: null,
            callback: function (): void {
                $stream = $this->openMemoryStream();

                $consoleProgressBar = new ConsoleProgressBar($stream, 10, isTty: true);
                $consoleProgressBar->start(1);
                $consoleProgressBar->advance('/tmp/Foo.php');
                $consoleProgressBar->finish();

                $output = $this->streamContents($stream);

                $this->assertStringContainsString('Analyzing [==========] 100% 1/1', $output);
                $this->assertStringNotContainsString("\033[", $output);
            }
        );
    }

    public function testProgressBarPrintsAtMostEvery10PercentWhenNotTty(): void
    {
        $stream = $this->openMemoryStream();

        $consoleProgressBar = new ConsoleProgressBar($stream, 10, false, false);
        $consoleProgressBar->start(93);

        for ($i = 0; $i < 93; $i++) {
            $consoleProgressBar->advance('/tmp/File' . $i . '.php');
        }

        $consoleProgressBar->finish();

        $output = $this->streamContents($stream);

        $this->assertStringContainsString('Analyzing [----------]   0% 0/93', $output);
        $this->assertStringContainsString('Analyzing [==========] 100% 93/93', $output);
        $this->assertStringNotContainsString("\r", $output);

        $lines = array_filter(explode("\n", trim($output)));
        $this->assertLessThanOrEqual(12, count($lines)); // 0% + at most 10 steps + 100%
    }

    public function testProgressBarHandlesZeroTotalWithoutRenderingFileName(): void
    {
        $stream = $this->openMemoryStream();

        $consoleProgressBar = new ConsoleProgressBar($stream, 8, true, true);
        $consoleProgressBar->start(0);
        $consoleProgressBar->advance('/tmp/VeryLongClassNameThatNeedsTruncating.php');
        $consoleProgressBar->finish();

        $output = $this->streamContents($stream);

        $this->assertStringContainsString('100% 0/0', $output);
        $this->assertStringNotContainsString('VeryLongClassNameThatNeedsTruncating.php', $output);
    }

    /**
     * @param callable(): void $callback
     */
    private function withEnvironment(?string $noColor, ?string $forceColor, callable $callback): void
    {
        $previousNoColor       = getenv('NO_COLOR');
        $previousForceColor    = getenv('FORCE_COLOR');
        $previousGithubActions = getenv('GITHUB_ACTIONS');

        $this->setEnvironment('NO_COLOR', $noColor);
        $this->setEnvironment('FORCE_COLOR', $forceColor);
        $this->setEnvironment('GITHUB_ACTIONS', null);

        try {
            $callback();
        } finally {
            $this->setEnvironment('NO_COLOR', $previousNoColor === false ? null : $previousNoColor);
            $this->setEnvironment('FORCE_COLOR', $previousForceColor === false ? null : $previousForceColor);
            $this->setEnvironment('GITHUB_ACTIONS', $previousGithubActions === false ? null : $previousGithubActions);
        }
    }

    private function setEnvironment(string $name, ?string $value): void
    {
        putenv($value === null ? $name : $name . '=' . $value);
    }
}
