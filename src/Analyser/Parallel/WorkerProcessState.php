<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Parallel;

use RuntimeException;

final class WorkerProcessState
{
    /** @var resource */
    public mixed $process;

    /** @var list<string> */
    public array $files = [];

    public int $filesAdvanced = 0;

    public ?RuntimeException $socketFailure = null;

    /** @var array<mixed>|null */
    public ?array $result = null;

    /** @var resource|null */
    public mixed $socket = null;

    /** @var resource */
    public mixed $stdoutPipe;

    /** @var resource */
    public mixed $stderrPipe;

    /**
     * @param resource $process
     * @param resource $stdoutPipe
     * @param resource $stderrPipe
     */
    public function __construct(
        public readonly string $workerId,
        mixed $process,
        array $files,
        mixed $stdoutPipe,
        mixed $stderrPipe,
    ) {
        $this->process = $process;
        $this->files = $files;
        $this->stdoutPipe = $stdoutPipe;
        $this->stderrPipe = $stderrPipe;
    }
}
