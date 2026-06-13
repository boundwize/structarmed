<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\Parallel\BufferedWorkerProgressHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function fopen;
use function rewind;
use function stream_get_contents;

#[CoversClass(BufferedWorkerProgressHandler::class)]
final class BufferedWorkerProgressHandlerTest extends TestCase
{
    public function testAdvanceWritesNewlineTokenToStream(): void
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertNotFalse($stream);

        $bufferedWorkerProgressHandler = new BufferedWorkerProgressHandler($stream);

        $bufferedWorkerProgressHandler->start(2);
        $bufferedWorkerProgressHandler->advance('/path/Foo.php');
        $bufferedWorkerProgressHandler->advance('/path/Bar.php');
        $bufferedWorkerProgressHandler->finish();

        rewind($stream);

        $this->assertSame("\n\n", stream_get_contents($stream));
    }
}
