<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\Parallel\WorkerProgressHandler;
use Boundwize\StructArmed\Tests\Support\InMemoryStreamTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkerProgressHandler::class)]
final class WorkerProgressHandlerTest extends TestCase
{
    use InMemoryStreamTrait;

    public function testAdvanceWritesNewlineTokenToStream(): void
    {
        $stream = $this->openMemoryStream();

        $workerProgressHandler = new WorkerProgressHandler($stream);

        $workerProgressHandler->start(2);
        $workerProgressHandler->advance('/path/Foo.php');
        $workerProgressHandler->advance('/path/Bar.php');
        $workerProgressHandler->finish();

        $this->assertSame("\n\n", $this->streamContents($stream));
    }
}
