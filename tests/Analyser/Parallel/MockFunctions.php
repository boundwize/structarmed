<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Parallel;

use function serialize;
use function str_contains;

// phpcs:disable
$GLOBALS['mock_proc_open']                 = false;
$GLOBALS['mock_tempnam']                   = false;
$GLOBALS['mock_file_get_contents_payload'] = null;
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

    return \proc_open($command, $descriptorspec, $pipes);
}

function tempnam(string $directory, string $prefix): string|false
{
    if ($GLOBALS['mock_tempnam'] === true) {
        return false;
    }

    return \tempnam($directory, $prefix);
}

function file_get_contents(string $filename): string|false
{
    if ($GLOBALS['mock_file_get_contents_payload'] !== null && str_contains($filename, 'worker-')) {
        $payload                                   = $GLOBALS['mock_file_get_contents_payload'];
        $GLOBALS['mock_file_get_contents_payload'] = null; // Reset after first read (outputFile)
        return serialize($payload);
    }

    return \file_get_contents($filename);
}
