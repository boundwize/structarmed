<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Support;

use Boundwize\StructArmed\Progress\ProgressHandlerInterface;

final class RecordingProgressHandler implements ProgressHandlerInterface
{
    /** @var list<string> */
    private array $advanced = [];

    public function start(int $total): void
    {
    }

    public function advance(string $file): void
    {
        $this->advanced[] = $file;
    }

    public function finish(): void
    {
    }

    /** @return list<string> */
    public function advanced(): array
    {
        return $this->advanced;
    }
}
