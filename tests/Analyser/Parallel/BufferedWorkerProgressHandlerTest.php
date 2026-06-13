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

        $progressHandler = new BufferedWorkerProgressHandler($stream);

        $progressHandler->start(2);
        $progressHandler->advance('/path/Foo.php');
        $progressHandler->advance('/path/Bar.php');
        $progressHandler->finish();

        rewind($stream);

        $this->assertSame("\n\n", stream_get_contents($stream));
    }
}
