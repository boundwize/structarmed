<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Parallel;

use RuntimeException;

final readonly class WorkerFinalizedState
{
    /**
     * @param array<mixed>|null $result
     */
    public function __construct(
        public ?array $result,
        public ?RuntimeException $socketFailure,
        public string $stderr,
        public int $exitCode,
    ) {
    }
}
