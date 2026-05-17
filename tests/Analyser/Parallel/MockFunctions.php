<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Parallel;

use function fclose;
use function file_put_contents;
use function is_array;
use function is_string;

use const PHP_BINARY;

// phpcs:disable
$GLOBALS['mock_proc_open'] = false;
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
        /** @var array{resultPayload?: string, stderrPayload?: string, progressPayload?: string} $mockProcOpen */
        $mockProcOpen = $GLOBALS['mock_proc_open'];

        if (is_array($command)) {
            $outputFile = $command[4] ?? null;
            if (is_string($outputFile) && isset($mockProcOpen['resultPayload'])) {
                file_put_contents($outputFile, $mockProcOpen['resultPayload']);
            }

            $progressFileDescriptor = $descriptorspec[1] ?? null;
            if (
                is_array($progressFileDescriptor)
                && isset($progressFileDescriptor[1])
                && isset($mockProcOpen['progressPayload'])
            ) {
                file_put_contents($progressFileDescriptor[1], $mockProcOpen['progressPayload']);
            }

            $stderrFileDescriptor = $descriptorspec[2] ?? null;
            if (
                is_array($stderrFileDescriptor)
                && isset($stderrFileDescriptor[1])
                && isset($mockProcOpen['stderrPayload'])
            ) {
                file_put_contents($stderrFileDescriptor[1], $mockProcOpen['stderrPayload']);
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
