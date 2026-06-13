<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Parallel;

use Boundwize\StructArmed\Progress\ProgressHandlerInterface;

use function fflush;
use function fwrite;

final readonly class WorkerProgressHandler implements ProgressHandlerInterface
{
    /** @param resource $stream */
    public function __construct(private mixed $stream)
    {
    }

    public function start(int $total): void
    {
    }

    public function advance(string $file): void
    {
        fwrite($this->stream, "\n");
        fflush($this->stream);
    }

    public function finish(): void
    {
    }
}
