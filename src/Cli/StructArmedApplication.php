<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cli;

use Boundwize\StructArmed\Analyser\Parallel\AnalysisNodeWorker;
use Boundwize\StructArmed\Version;

use function array_slice;
use function array_values;
use function getcwd;
use function in_array;
use function sprintf;

final readonly class StructArmedApplication
{
    private const COMMANDS = ['init', 'analyse', 'analyze', '--clear-cache', '--version', '-V', '--help', '-h'];

    /**
     * @param list<string> $argv
     */
    public function run(array $argv, ?string $basePath = null): int
    {
        $basePath ??= (string) getcwd();
        $arguments = array_slice($argv, 1);
        $command   = $arguments[0] ?? null;

        if ($command === '--internal-worker') {
            return AnalysisNodeWorker::run($argv[2] ?? '', $argv[3] ?? '');
        }

        // Options may precede the command: `structarmed --basepath=../../ --clear-cache`.
        foreach ($arguments as $index => $argument) {
            if (in_array($argument, self::COMMANDS, true)) {
                $command = $argument;
                unset($arguments[$index]);
                break;
            }
        }

        $arguments = array_values($arguments);

        if (in_array($command, ['--version', '-V'], true)) {
            echo sprintf("StructArmed %s\n", Version::current());

            return 0;
        }

        if (in_array($command, [null, '--help', '-h'], true)) {
            echo Usage::render();

            return 0;
        }

        if ($command === 'init') {
            return (new InitCommand())->run($arguments, $basePath);
        }

        if ($command === '--clear-cache') {
            return (new ClearCacheCommand())->run($arguments, $basePath);
        }

        if (in_array($command, ['analyse', 'analyze'], true)) {
            return (new AnalyseCommand())->run($arguments, $basePath);
        }

        echo sprintf("Unknown command: %s\n\n", $command);
        echo Usage::render();

        return 1;
    }
}
