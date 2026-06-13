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

        $workerProgressHandler = new WorkerProgressHandler($stream);

        $workerProgressHandler->start(2);
        $workerProgressHandler->advance('/path/Foo.php');
        $workerProgressHandler->advance('/path/Bar.php');
        $workerProgressHandler->finish();

        rewind($stream);

        $this->assertSame("\n\n", stream_get_contents($stream));
    }
}
