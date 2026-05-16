<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Parallel;

use function fclose;
use function is_array;

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
        /** @var array{pipes: array<int, resource>} $mockProcOpen */
        $mockProcOpen = $GLOBALS['mock_proc_open'];

        $process = \proc_open([PHP_BINARY, '-r', ''], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $realPipes);

        if ($process === false) {
            return false;
        }

        foreach ($realPipes as $realPipe) {
            fclose($realPipe);
        }

        $pipes = $mockProcOpen['pipes'];

        return $process;
    }

    /** @var array<int, resource>|null $nativePipes */
    $nativePipes = null;

    $process = \proc_open($command, $descriptorspec, $nativePipes);
    $pipes   = $nativePipes;

    return $process;
}
