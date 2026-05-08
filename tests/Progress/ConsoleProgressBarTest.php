<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Progress;

use Boundwize\StructArmed\Progress\ConsoleProgressBar;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function fopen;
use function rewind;
use function stream_get_contents;

#[CoversClass(ConsoleProgressBar::class)]
final class ConsoleProgressBarTest extends TestCase
{
    public function testProgressBarRendersFinalState(): void
    {
        $stream = fopen('php://temp', 'w+');
        $this->assertIsResource($stream);

        $consoleProgressBar = new ConsoleProgressBar($stream, 10);
        $consoleProgressBar->start(2);
        $consoleProgressBar->advance('/tmp/Foo.php');
        $consoleProgressBar->advance('/tmp/Bar.php');
        $consoleProgressBar->finish();

        rewind($stream);
        $output = (string) stream_get_contents($stream);

        $this->assertStringContainsString('Analyzing [==========] 100% 2/2', $output);
        $this->assertStringContainsString('Foo.php', $output);
        $this->assertStringContainsString('Bar.php', $output);
    }

    public function testProgressBarCanRenderWithColor(): void
    {
        $stream = fopen('php://temp', 'w+');
        $this->assertIsResource($stream);

        $consoleProgressBar = new ConsoleProgressBar($stream, 10, true);
        $consoleProgressBar->start(1);
        $consoleProgressBar->advance('/tmp/Foo.php');
        $consoleProgressBar->finish();

        rewind($stream);
        $output = (string) stream_get_contents($stream);

        $this->assertStringContainsString("\033[36mAnalyzing\033[0m", $output);
        $this->assertStringContainsString("\033[32m==========\033[0m", $output);
        $this->assertStringContainsString('100% 1/1', $output);
    }
}
