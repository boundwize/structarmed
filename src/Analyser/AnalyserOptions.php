<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use Fidry\CpuCoreCounter\CpuCoreCounter;

use function function_exists;
use function max;

final readonly class AnalyserOptions
{
    private function __construct(
        public int $workerCount,
    ) {
    }

    public static function sequential(): self
    {
        return new self(1);
    }

    public static function parallel(?int $workerCount = null): self
    {
        if ($workerCount !== null) {
            return new self(max(1, $workerCount));
        }

        return new self((new CpuCoreCounter())->getAvailableForParallelisation()->availableCpus);
    }

    public function isParallel(): bool
    {
        if ($this->workerCount <= 1) {
            return false;
        }

        return function_exists('proc_open');
    }
}
