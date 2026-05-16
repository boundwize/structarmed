<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cli;

use Boundwize\StructArmed\Analyser\Parallel\ClassNodeWorker;

use function array_slice;
use function assert;
use function fopen;
use function getcwd;
use function in_array;
use function sprintf;

use const STDIN;

final readonly class StructArmedApplication
{
    /**
     * @param list<string> $argv
     */
    public function run(array $argv, ?string $basePath = null): int
    {
        $basePath ??= (string) getcwd();
        $command    = $argv[1] ?? null;

        if ($command === '--internal-worker') {
            // phpcs:disable SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly.ReferenceViaFallbackGlobalName
            $resultFd = fopen('php://fd/3', 'w');
            // phpcs:enable
            assert($resultFd !== false);

            return ClassNodeWorker::run(STDIN, $resultFd);
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
