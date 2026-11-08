<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Parallel;

use RuntimeException;

use function array_slice;
use function in_array;
use function is_int;
use function pcntl_exec;
use function serialize;
use function sprintf;
use function str_replace;

// phpcs:disable
$GLOBALS['mock_proc_open']                 = false;
$GLOBALS['mock_proc_open_command']         = null;
$GLOBALS['mock_tempnam']                   = false;
$GLOBALS['mock_stream_select']             = false;
$GLOBALS['mock_file_get_contents_payload'] = null;
$GLOBALS['mock_tracked_tempnam_files']     = [];
$GLOBALS['mock_stream_socket_pair']        = false;
$GLOBALS['mock_pcntl_fork']                = null;
$GLOBALS['mock_posix_kill']                = false;
$GLOBALS['mock_pcntl_waitpid']             = false;
$GLOBALS['mock_missing_functions']         = [];
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

    if ($GLOBALS['mock_proc_open_command'] !== null) {
        /** @var list<string> $command */
        $command = $GLOBALS['mock_proc_open_command'];
    }

    return \proc_open($command, $descriptorspec, $pipes);
}

/**
 * A mocked proc_open() is only reached on the exec'd worker path, so its tests run without pcntl_fork().
 */
function function_exists(string $function): bool
{
    if (
        $function === 'pcntl_fork'
        && ($GLOBALS['mock_proc_open'] === true || $GLOBALS['mock_proc_open_command'] !== null)
    ) {
        return false;
    }

    if (in_array($function, (array) $GLOBALS['mock_missing_functions'], true)) {
        return false;
    }

    return \function_exists($function);
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

/**
 * @param array<int, resource>|null $read
 * @param array<int, resource>|null $write
 * @param array<int, resource>|null $except
 */
function stream_select(?array &$read, ?array &$write, ?array &$except, ?int $seconds): int|false
{
    if ($GLOBALS['mock_stream_select'] === true) {
        return false;
    }

    return \stream_select($read, $write, $except, $seconds);
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

/**
 * @return array<resource>|false
 */
function stream_socket_pair(int $domain, int $type, int $protocol): array|false
{
    if ($GLOBALS['mock_stream_socket_pair'] === true) {
        return false;
    }

    return \stream_socket_pair($domain, $type, $protocol);
}

/**
 * An int is returned as the fork result (-1 failed, 0 pretends to be the child); a command
 * forks for real and replaces the child with it, so the child never runs PHPUnit's shutdown.
 */
function pcntl_fork(): int
{
    /** @var int|list<string>|null $mock */
    $mock = $GLOBALS['mock_pcntl_fork'];

    if ($mock === null) {
        return \pcntl_fork();
    }

    if (is_int($mock)) {
        return $mock;
    }

    $pid = \pcntl_fork();

    if ($pid === 0) {
        pcntl_exec($mock[0], array_slice($mock, 1));
    }

    return $pid;
}

/**
 * Only reached by a child pretended in-process; stops it before it kills the test runner.
 */
function posix_kill(int $processId, int $signal): bool
{
    if ($GLOBALS['mock_posix_kill'] === true) {
        throw new RuntimeException(sprintf('posix_kill(%d, %d)', $processId, $signal));
    }

    return \posix_kill($processId, $signal);
}

/**
 * Reaps the child for real, then reports the status as already collected elsewhere.
 */
function pcntl_waitpid(int $processId, mixed &$status): int
{
    $result = \pcntl_waitpid($processId, $status);

    return $GLOBALS['mock_pcntl_waitpid'] === true ? -1 : $result;
}
