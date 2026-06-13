<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\Parallel\WorkerProgressHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function fopen;
use function rewind;
use function stream_get_contents;

#[CoversClass(WorkerProgressHandler::class)]
final class WorkerProgressHandlerTest extends TestCase
{
    public function testAdvanceWritesNewlineTokenToStream(): void
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertNotFalse($stream);

        $WorkerProgressHandler = new WorkerProgressHandler($stream);

        $WorkerProgressHandler->start(2);
        $WorkerProgressHandler->advance('/path/Foo.php');
        $WorkerProgressHandler->advance('/path/Bar.php');
        $WorkerProgressHandler->finish();

        rewind($stream);

        $this->assertSame("\n\n", stream_get_contents($stream));
    }
}
