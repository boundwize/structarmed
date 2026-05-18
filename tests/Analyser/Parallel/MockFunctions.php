<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Parallel;

use function assert;
use function is_array;
use function is_callable;
use function is_int;
use function is_resource;

use const STREAM_CLIENT_CONNECT;

// phpcs:disable
$GLOBALS['mock_proc_open']                     = false;
$GLOBALS['mock_proc_open_callback']            = null;
$GLOBALS['mock_proc_open_command']             = null;
$GLOBALS['mock_stream_select_callback']        = null;
$GLOBALS['mock_stream_socket_client_callback'] = null;
$GLOBALS['mock_unpack_callback']               = null;
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

/**
 * @param array<int, resource>|null $read
 * @param array<int, resource>|null $write
 * @param array<int, resource>|null $except
 */
function stream_select(
    ?array &$read,
    ?array &$write,
    ?array &$except,
    ?int $seconds,
    ?int $microseconds = null
): int|false {
    if (is_callable($GLOBALS['mock_stream_select_callback'])) {
        $result = $GLOBALS['mock_stream_select_callback']($read, $write, $except, $seconds, $microseconds);
        assert(is_int($result) || $result === false);

        return $result;
    }

    return \stream_select($read, $write, $except, $seconds, $microseconds);
}

/**
 * @param int<0, 7> $flags
 * @param resource|null $context
 * @return resource|false
 */
function stream_socket_client(
    string $address,
    ?int &$errorCode = null,
    ?string &$errorMessage = null,
    ?float $timeout = null,
    int $flags = STREAM_CLIENT_CONNECT,
    mixed $context = null
): mixed {
    if (is_callable($GLOBALS['mock_stream_socket_client_callback'])) {
        $result = $GLOBALS['mock_stream_socket_client_callback'](
            $address,
            $errorCode,
            $errorMessage,
            $timeout,
            $flags,
            $context,
        );
        assert(is_resource($result) || $result === false);

        return $result;
    }

    return \stream_socket_client($address, $errorCode, $errorMessage, $timeout, $flags, $context);
}

/**
 * @return array<mixed>|false
 */
function unpack(string $format, string $string, int $offset = 0): array|false
{
    if (is_callable($GLOBALS['mock_unpack_callback'])) {
        $result = $GLOBALS['mock_unpack_callback']($format, $string, $offset);
        assert(is_array($result) || $result === false);

        return $result;
    }

    return \unpack($format, $string, $offset);
}
