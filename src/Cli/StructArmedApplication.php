<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cli;

use Boundwize\StructArmed\Analyser\Parallel\AnalysisNodeWorker;
use Boundwize\StructArmed\Util\Path;
use Boundwize\StructArmed\Version;

use function array_slice;
use function array_values;
use function file_get_contents;
use function getcwd;
use function in_array;
use function is_dir;
use function sprintf;
use function unserialize;

final readonly class StructArmedApplication
{
    private const COMMANDS = [
        'init',
        'append',
        'analyse',
        'analyze',
        '--clear-cache',
        '--version',
        '-V',
        '--help',
        '-h',
    ];

    /**
     * @param list<string> $argv
     */
    public function run(array $argv, ?string $basePath = null): int
    {
        $basePath ??= (string) getcwd();
        $arguments = array_slice($argv, 1);
        $command   = $arguments[0] ?? null;

        if ($command === '--internal-worker') {
            return AnalysisNodeWorker::run(unserialize((string) file_get_contents($argv[2] ?? '')), $argv[3] ?? '');
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

        // A help option may follow the command: `structarmed analyse --help`. A directory
        // named `-h` or `--help` is a path to analyse instead of a request for help.
        foreach ($arguments as $argument) {
            if (in_array($argument, ['--help', '-h'], true) && ! is_dir(Path::resolve($argument, $basePath))) {
                $command = $argument;
                break;
            }
        }

        if (in_array($command, [null, '--help', '-h'], true)) {
            return (new HelpCommand())->run();
        }

        if ($command === 'init') {
            return (new InitCommand())->run($arguments, $basePath);
        }

        if ($command === 'append') {
            return (new AppendCommand())->run($arguments, $basePath);
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
