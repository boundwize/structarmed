<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Parallel\ValueObject;

use Boundwize\StructArmed\Analyser\ClassNode;
use RuntimeException;

final class WorkerProcessState
{
    public int $filesAdvanced = 0;

    public string $stderrBuffer = '';

    public ?RuntimeException $resultFailure = null;

    /** @var array{nodes: list<ClassNode>, error: string|null}|null */
    public ?array $result = null;

    /**
     * @param resource $process
     * @param resource|null $resultPipe
     * @param resource $stderrPipe
     */
    public function __construct(
        public readonly string $workerId,
        public mixed $process,
        /** @var list<string> */
        public array $files,
        public mixed $stderrPipe,
        public mixed $resultPipe = null,
    ) {
    }
}
