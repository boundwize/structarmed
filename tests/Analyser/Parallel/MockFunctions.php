<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Parallel;

use function fclose;
use function file_put_contents;
use function is_array;
use function is_int;
use function is_string;

use const PHP_BINARY;

// phpcs:disable
$GLOBALS['mock_proc_open'] = false;
$GLOBALS['mock_proc_open_calls'] = 0;
$GLOBALS['mock_is_dir'] = null;
$GLOBALS['mock_mkdir_calls'] = 0;
$GLOBALS['mock_tempnam'] = null;
// phpcs:enable

/**
 * @param list<string>|string $command
 * @param array<int, list<string>|resource> $descriptorspec
 * @param array<int, resource>|null $pipes
 * @param-out array<int, resource> $pipes
 */
function proc_open(array|string $command, array $descriptorspec, array|null &$pipes): mixed
{
    if ($GLOBALS['mock_proc_open'] === true) {
        return false;
    }

    if (is_array($GLOBALS['mock_proc_open'])) {
        $procOpenCalls                   = $GLOBALS['mock_proc_open_calls'];
        $GLOBALS['mock_proc_open_calls'] = is_int($procOpenCalls) ? $procOpenCalls + 1 : 1;

        /** @var array{failOnCall?: int, resultPayload?: string, stderrPayload?: string, progressPayload?: string} $mockProcOpen */
        $mockProcOpen = $GLOBALS['mock_proc_open'];

        if (($mockProcOpen['failOnCall'] ?? null) === $GLOBALS['mock_proc_open_calls']) {
            return false;
        }

        if (is_array($command)) {
            $outputFile = $command[4] ?? null;
            if (is_string($outputFile) && isset($mockProcOpen['resultPayload'])) {
                file_put_contents($outputFile, $mockProcOpen['resultPayload']);
            }

            $stderrFileDescriptor = $descriptorspec[2] ?? null;
            if (
                is_array($stderrFileDescriptor)
                && isset($stderrFileDescriptor[1])
                && (isset($mockProcOpen['progressPayload']) || isset($mockProcOpen['stderrPayload']))
            ) {
                file_put_contents(
                    $stderrFileDescriptor[1],
                    ($mockProcOpen['progressPayload'] ?? '') . ($mockProcOpen['stderrPayload'] ?? '')
                );
            }
        }

        $process = \proc_open([PHP_BINARY, '-r', ''], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $realPipes);

        if ($process === false) {
            return false;
        }

        fclose($realPipes[1]);
        fclose($realPipes[2]);

        $pipes = [0 => $realPipes[0]];

        return $process;
    }

    /** @var array<int, resource>|null $nativePipes */
    $nativePipes = null;

    $process = \proc_open($command, $descriptorspec, $nativePipes);
    $pipes   = $nativePipes;

    return $process;
}

function is_dir(string $filename): bool
{
    if ($GLOBALS['mock_is_dir'] !== null) {
        return (bool) $GLOBALS['mock_is_dir'];
    }

    return \is_dir($filename);
}

function mkdir(string $directory, int $permissions = 0777, bool $recursive = false): bool
{
    $mkdirCalls                  = $GLOBALS['mock_mkdir_calls'];
    $GLOBALS['mock_mkdir_calls'] = is_int($mkdirCalls) ? $mkdirCalls + 1 : 1;

    if ($GLOBALS['mock_is_dir'] !== null) {
        return true;
    }

    return \mkdir($directory, $permissions, $recursive);
}

function tempnam(string $directory, string $prefix): string|false
{
    if ($GLOBALS['mock_tempnam'] !== null) {
        $mockTempnam = $GLOBALS['mock_tempnam'];

        return is_string($mockTempnam) ? $mockTempnam : false;
    }

    return \tempnam($directory, $prefix);
}
