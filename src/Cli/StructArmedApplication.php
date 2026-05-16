<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cli;

use Boundwize\StructArmed\Analyser\Parallel\ClassNodeWorker;
use Closure;

use function array_slice;
use function assert;
use function getcwd;
use function in_array;
use function is_resource;
use function sprintf;

use const STDERR;
use const STDIN;
use const STDOUT;

final readonly class StructArmedApplication
{
    /**
     * @param resource $workerInput
     * @param Closure(): mixed|null $resultStreamOpener
     * @param Closure(): mixed|null $progressStreamOpener
     */
    public function __construct(
        private mixed $workerInput = STDIN,
        private ?Closure $resultStreamOpener = null,
        private ?Closure $progressStreamOpener = null,
    ) {
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv, ?string $basePath = null): int
    {
        $basePath ??= (string) getcwd();
        $command    = $argv[1] ?? null;

        if ($command === '--internal-worker') {
            $resultFd   = $this->resultStreamOpener instanceof Closure ? ($this->resultStreamOpener)() : STDOUT;
            $progressFd = $this->progressStreamOpener instanceof Closure ? ($this->progressStreamOpener)() : STDERR;

            assert(is_resource($resultFd));
            assert(is_resource($progressFd));
            assert(is_resource($this->workerInput));

            return ClassNodeWorker::run($this->workerInput, $resultFd, $progressFd);
        }

        if (in_array($command, [null, '--help', '-h'], true)) {
            echo Usage::render();

            return 0;
        }

        if ($command === 'init') {
            return (new InitCommand())->run(array_slice($argv, 2), $basePath);
        }

        if ($command === '--clear-cache') {
            return (new ClearCacheCommand())->run(array_slice($argv, 2), $basePath);
        }

        if (in_array($command, ['analyse', 'analyze'], true)) {
            return (new AnalyseCommand())->run(array_slice($argv, 2), $basePath);
        }

        echo sprintf("Unknown command: %s\n\n", $command);
        echo Usage::render();

        return 1;
    }
}
