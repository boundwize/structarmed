<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Progress;

use Boundwize\StructArmed\Progress\ConsoleProgressBar;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function fopen;
use function getenv;
use function putenv;
use function rewind;
use function stream_get_contents;

#[CoversClass(ConsoleProgressBar::class)]
final class ConsoleProgressBarTest extends TestCase
{
    public function testProgressBarRendersFinalState(): void
    {
        $stream = fopen('php://temp', 'w+');
        $this->assertIsResource($stream);

        $consoleProgressBar = new ConsoleProgressBar($stream, 10, isTty: true);
        $consoleProgressBar->start(2);
        $consoleProgressBar->advance('/tmp/Foo.php');
        $consoleProgressBar->advance('/tmp/Bar.php');
        $consoleProgressBar->finish();

        rewind($stream);
        $output = (string) stream_get_contents($stream);

        $this->assertStringContainsString('Analyzing [==========] 100% 2/2', $output);
        $this->assertStringNotContainsString('Foo.php', $output);
        $this->assertStringNotContainsString('Bar.php', $output);
    }

    public function testProgressBarCanRenderWithColor(): void
    {
        $stream = fopen('php://temp', 'w+');
        $this->assertIsResource($stream);

        $consoleProgressBar = new ConsoleProgressBar($stream, 10, true, true);
        $consoleProgressBar->start(1);
        $consoleProgressBar->advance('/tmp/Foo.php');
        $consoleProgressBar->finish();

        rewind($stream);
        $output = (string) stream_get_contents($stream);

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
                $stream = fopen('php://temp', 'w+');
                $this->assertIsResource($stream);

                $consoleProgressBar = new ConsoleProgressBar($stream, 10, isTty: true);
                $consoleProgressBar->start(1);
                $consoleProgressBar->advance('/tmp/Foo.php');
                $consoleProgressBar->finish();

                rewind($stream);
                $output = (string) stream_get_contents($stream);

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
                $stream = fopen('php://temp', 'w+');
                $this->assertIsResource($stream);

                $consoleProgressBar = new ConsoleProgressBar($stream, 10, isTty: true);
                $consoleProgressBar->start(1);
                $consoleProgressBar->advance('/tmp/Foo.php');
                $consoleProgressBar->finish();

                rewind($stream);
                $output = (string) stream_get_contents($stream);

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
                $stream = fopen('php://temp', 'w+');
                $this->assertIsResource($stream);

                $consoleProgressBar = new ConsoleProgressBar($stream, 10, isTty: true);
                $consoleProgressBar->start(1);
                $consoleProgressBar->advance('/tmp/Foo.php');
                $consoleProgressBar->finish();

                rewind($stream);
                $output = (string) stream_get_contents($stream);

                $this->assertStringContainsString('Analyzing [==========] 100% 1/1', $output);
                $this->assertStringNotContainsString("\033[", $output);
            }
        );
    }

    public function testProgressBarPrintsAtMostEvery10PercentWhenNotTty(): void
    {
        $stream = fopen('php://temp', 'w+');
        $this->assertIsResource($stream);

        $consoleProgressBar = new ConsoleProgressBar($stream, 10, null, false);
        $consoleProgressBar->start(93);

        for ($i = 0; $i < 93; $i++) {
            $consoleProgressBar->advance('/tmp/File' . $i . '.php');
        }

        $consoleProgressBar->finish();

        rewind($stream);
        $output = (string) stream_get_contents($stream);

        $this->assertStringContainsString('Analyzing [----------]   0% 0/93', $output);
        $this->assertStringContainsString('Analyzing [==========] 100% 93/93', $output);
        $this->assertStringNotContainsString("\r", $output);

        $lines = array_filter(explode("\n", trim($output)));
        $this->assertLessThanOrEqual(12, count($lines)); // 0% + at most 10 steps + 100%
    }

    public function testProgressBarHandlesZeroTotalWithoutRenderingFileName(): void
    {
        $stream = fopen('php://temp', 'w+');
        $this->assertIsResource($stream);

        $consoleProgressBar = new ConsoleProgressBar($stream, 8, true, true);
        $consoleProgressBar->start(0);
        $consoleProgressBar->advance('/tmp/VeryLongClassNameThatNeedsTruncating.php');
        $consoleProgressBar->finish();

        rewind($stream);
        $output = (string) stream_get_contents($stream);

        $this->assertStringContainsString('100% 0/0', $output);
        $this->assertStringNotContainsString('VeryLongClassNameThatNeedsTruncating.php', $output);
    }

    /**
     * @param callable(): void $callback
     */
    private function withEnvironment(?string $noColor, ?string $forceColor, callable $callback): void
    {
        $previousNoColor    = getenv('NO_COLOR');
        $previousForceColor = getenv('FORCE_COLOR');

        $this->setEnvironment('NO_COLOR', $noColor);
        $this->setEnvironment('FORCE_COLOR', $forceColor);

        try {
            $callback();
        } finally {
            $this->setEnvironment('NO_COLOR', $previousNoColor === false ? null : $previousNoColor);
            $this->setEnvironment('FORCE_COLOR', $previousForceColor === false ? null : $previousForceColor);
        }
    }

    private function setEnvironment(string $name, ?string $value): void
    {
        putenv($value === null ? $name : $name . '=' . $value);
    }
}
