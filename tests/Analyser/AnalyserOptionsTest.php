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
        $analyserOptions = AnalyserOptions::sequential();

        $this->assertSame(1, $analyserOptions->workerCount);
    }

    public function testParallelWithExplicitCountReturnsProvidedWorkerCount(): void
    {
        $analyserOptions = AnalyserOptions::parallel(4);

        $this->assertSame(4, $analyserOptions->workerCount);
    }

    public function testParallelClampsWorkerCountToMinimumOfOne(): void
    {
        $analyserOptions = AnalyserOptions::parallel(0);

        $this->assertSame(1, $analyserOptions->workerCount);
    }

    public function testParallelWithNegativeCountClampsToOne(): void
    {
        $analyserOptions = AnalyserOptions::parallel(-5);

        $this->assertSame(1, $analyserOptions->workerCount);
    }

    public function testParallelWithNoArgumentAutoDetectsWorkerCount(): void
    {
        $analyserOptions = AnalyserOptions::parallel();

        $this->assertGreaterThanOrEqual(1, $analyserOptions->workerCount);
    }

    public function testIsParallelReturnsTrueWhenWorkerCountGreaterThanOne(): void
    {
        $analyserOptions = AnalyserOptions::parallel(2);

        $this->assertTrue($analyserOptions->isParallel());
    }

    public function testIsParallelReturnsFalseForSequential(): void
    {
        $analyserOptions = AnalyserOptions::sequential();

        $this->assertFalse($analyserOptions->isParallel());
    }

    public function testIsParallelReturnsFalseWhenWorkerCountIsOne(): void
    {
        $analyserOptions = AnalyserOptions::parallel(1);

        $this->assertFalse($analyserOptions->isParallel());
    }
}
