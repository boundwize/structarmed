<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\ClassNode;
use RuntimeException;

final readonly class WorkerFinalizedState
{
    /**
     * @param array{nodes: list<ClassNode>, error: string|null}|null $result
     */
    public function __construct(
        public ?array $result,
        public ?RuntimeException $resultFailure,
        public string $stderr,
        public int $exitCode,
    ) {
    }
}
