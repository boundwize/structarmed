<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Parallel;

use function in_array;
use function is_callable;
use function serialize;
use function str_replace;

// phpcs:disable
$GLOBALS['mock_proc_open']                 = false;
$GLOBALS['mock_proc_open_callback']        = null;
$GLOBALS['mock_proc_open_command']         = null;
$GLOBALS['mock_tempnam']                   = false;
$GLOBALS['mock_file_get_contents_payload'] = null;
$GLOBALS['mock_tracked_tempnam_files']     = [];
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

    if (is_callable($GLOBALS['mock_proc_open_callback'])) {
        return $GLOBALS['mock_proc_open_callback']($command, $descriptorspec, $pipes);
    }

    if ($GLOBALS['mock_proc_open_command'] !== null) {
        /** @var list<string>|string $command */
        $command = $GLOBALS['mock_proc_open_command'];
    }

    return \proc_open($command, $descriptorspec, $pipes);
}

function tempnam(string $directory, string $prefix): string|false
{
    if ($GLOBALS['mock_tempnam'] === true) {
        return false;
    }

    $result = \tempnam($directory, $prefix);

    if ($result !== false) {
        /** @var list<string> $tracked */
        $tracked                               = $GLOBALS['mock_tracked_tempnam_files'];
        $tracked[]                             = str_replace('\\', '/', $result);
        $GLOBALS['mock_tracked_tempnam_files'] = $tracked;
    }

    return $result;
}

function file_get_contents(string $filename): string|false
{
    if (
        $GLOBALS['mock_file_get_contents_payload'] !== null
        && in_array(str_replace('\\', '/', $filename), (array) $GLOBALS['mock_tracked_tempnam_files'], true)
    ) {
        $payload                                   = $GLOBALS['mock_file_get_contents_payload'];
        $GLOBALS['mock_file_get_contents_payload'] = null; // Reset after first read (outputFile)
        return serialize($payload);
    }

    return \file_get_contents($filename);
}
