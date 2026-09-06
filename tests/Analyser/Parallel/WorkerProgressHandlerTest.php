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

    public function testWritesParseCountThenChunkIndexOfEachAdvancedFile(): void
    {
        $stream = $this->openMemoryStream();

        $workerProgressHandler = new WorkerProgressHandler(
            $stream,
            ['/path/Foo.php', '/path/Bar.php', '/path/Baz.php']
        );

        $workerProgressHandler->start(2);
        $workerProgressHandler->advance('/path/Baz.php');
        $workerProgressHandler->advance('/path/Foo.php');
        $workerProgressHandler->finish();

        $this->assertSame("2\n2\n0\n", $this->streamContents($stream));
    }
}
