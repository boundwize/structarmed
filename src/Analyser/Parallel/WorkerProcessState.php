<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\ClassNode;
use RuntimeException;

final class WorkerProcessState
{
    public int $filesAdvanced = 0;

    public ?RuntimeException $socketFailure = null;

    /** @var array{nodes: list<ClassNode>, error: string|null}|null */
    public ?array $result = null;

    /** @var resource|null */
    public $socket;

    /**
     * @param resource $process
     * @param resource $stdoutPipe
     * @param resource $stderrPipe
     */
    public function __construct(
        public readonly string $workerId,
        public mixed $process,
        /** @var list<string> */
        public array $files,
        public mixed $stdoutPipe,
        public mixed $stderrPipe
    ) {
    }
}
