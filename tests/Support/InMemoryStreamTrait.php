<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Support;

use function fopen;
use function rewind;
use function stream_get_contents;

trait InMemoryStreamTrait
{
    /**
     * @return resource
     */
    protected function openMemoryStream()
    {
        $stream = fopen('php://temp', 'w+');
        $this->assertIsResource($stream);

        return $stream;
    }

    /**
     * @param resource $stream
     */
    protected function streamContents($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
