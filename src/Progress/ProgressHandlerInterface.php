<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Progress;

interface ProgressHandlerInterface
{
    public function start(int $total): void;

    public function advance(string $file): void;

    public function finish(): void;
}
