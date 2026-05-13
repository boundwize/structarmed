<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser;

use Boundwize\StructArmed\Analyser\AnalyserOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AnalyserOptions::class)]
final class AnalyserOptionsTest extends TestCase
{
    public function testSequentialReturnsWorkerCountOfOne(): void
    {
        $options = AnalyserOptions::sequential();

        $this->assertSame(1, $options->workerCount);
    }

    public function testParallelWithExplicitCountReturnsProvidedWorkerCount(): void
    {
        $options = AnalyserOptions::parallel(4);

        $this->assertSame(4, $options->workerCount);
    }

    public function testParallelClampsWorkerCountToMinimumOfOne(): void
    {
        $options = AnalyserOptions::parallel(0);

        $this->assertSame(1, $options->workerCount);
    }

    public function testParallelWithNegativeCountClampsToOne(): void
    {
        $options = AnalyserOptions::parallel(-5);

        $this->assertSame(1, $options->workerCount);
    }

    public function testParallelWithNoArgumentAutoDetectsWorkerCount(): void
    {
        $options = AnalyserOptions::parallel();

        $this->assertGreaterThanOrEqual(1, $options->workerCount);
    }

    public function testIsParallelReturnsTrueWhenWorkerCountGreaterThanOne(): void
    {
        $options = AnalyserOptions::parallel(2);

        $this->assertTrue($options->isParallel());
    }

    public function testIsParallelReturnsFalseForSequential(): void
    {
        $options = AnalyserOptions::sequential();

        $this->assertFalse($options->isParallel());
    }

    public function testIsParallelReturnsFalseWhenWorkerCountIsOne(): void
    {
        $options = AnalyserOptions::parallel(1);

        $this->assertFalse($options->isParallel());
    }
}
