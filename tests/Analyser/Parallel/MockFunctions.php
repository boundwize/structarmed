<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Parallel;

use function serialize;
use function strlen;
use function var_export;

use const PHP_BINARY;

// phpcs:disable
$GLOBALS['mock_proc_open']      = false;
$GLOBALS['mock_stdout_payload'] = null;
// phpcs:enable

/**
 * @param list<string>|string $command
 * @param array<int, list<string>|resource> $descriptorspec
 * @param array<int, resource> $pipes
 */
function proc_open(array|string $command, array $descriptorspec, array|null &$pipes): mixed
{
    if ($GLOBALS['mock_proc_open'] === true) {
        return false;
    }

    if ($GLOBALS['mock_stdout_payload'] !== null) {
        $data                           = serialize($GLOBALS['mock_stdout_payload']);
        $GLOBALS['mock_stdout_payload'] = null;
        $output                         = 'RESULT:' . strlen($data) . "\n" . $data;
        $script                         = 'stream_get_contents(STDIN); echo ' . var_export($output, true) . ';';

        return \proc_open([PHP_BINARY, '-r', $script], $descriptorspec, $pipes);
    }

    return \proc_open($command, $descriptorspec, $pipes);
}
