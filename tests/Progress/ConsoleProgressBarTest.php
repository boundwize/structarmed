<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Progress;

use Boundwize\StructArmed\Progress\ConsoleProgressBar;
use Boundwize\StructArmed\Tests\Support\InMemoryStreamTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function getenv;
use function putenv;

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

    public function testProgressBarPrintsEvery10PercentWhenNotTty(): void
    {
        $stream = $this->openMemoryStream();

        $consoleProgressBar = new ConsoleProgressBar($stream, 10, false, false);
        $consoleProgressBar->start(93);

        for ($i = 0; $i < 93; $i++) {
            $consoleProgressBar->advance('/tmp/File' . $i . '.php');
        }

        $consoleProgressBar->finish();

        $output = $this->streamContents($stream);

        $this->assertSame(
            <<<'OUTPUT'
            Analyzing [----------]   0% 0/93
            Analyzing [=---------]  10% 10/93
            Analyzing [==--------]  20% 19/93
            Analyzing [===-------]  30% 28/93
            Analyzing [====------]  40% 38/93
            Analyzing [=====-----]  50% 47/93
            Analyzing [======----]  60% 56/93
            Analyzing [=======---]  70% 66/93
            Analyzing [========--]  80% 75/93
            Analyzing [=========-]  90% 84/93
            Analyzing [==========] 100% 93/93

            OUTPUT,
            $output
        );
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
