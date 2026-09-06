<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Parallel;

use Boundwize\StructArmed\Progress\ProgressHandlerInterface;

use function array_flip;
use function fwrite;

/**
 * Reports progress to the coordinator as one line per event: first the number
 * of files this worker has to parse, then the chunk index of each parsed file.
 */
final readonly class WorkerProgressHandler implements ProgressHandlerInterface
{
    /** @var array<string, int> */
    private array $fileIndexes;

    /**
     * @param resource     $stream
     * @param list<string> $files The worker's chunk, in the order the coordinator assigned it
     */
    public function __construct(private mixed $stream, array $files)
    {
        $this->fileIndexes = array_flip($files);
    }

    public function start(int $total): void
    {
        fwrite($this->stream, $total . "\n");
    }

    public function advance(string $file): void
    {
        fwrite($this->stream, ($this->fileIndexes[$file] ?? -1) . "\n");
    }

    public function finish(): void
    {
    }
}
