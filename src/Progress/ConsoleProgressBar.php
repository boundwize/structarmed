<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Progress;

use function fflush;
use function fprintf;
use function getenv;
use function max;
use function min;
use function str_repeat;
use function stream_isatty;

use const PHP_EOL;
use const STDERR;

final class ConsoleProgressBar implements ProgressHandlerInterface
{
    /** @var resource */
    private readonly mixed $stream;

    private int $total = 0;

    private int $current = 0;

    private readonly int $width;

    private readonly bool $useColor;

    private readonly bool $isTty;

    /**
     * @param resource|null $stream
     */
    public function __construct(mixed $stream = null, int $width = 28, ?bool $useColor = null, ?bool $isTty = null)
    {
        $this->stream   = $stream ?? STDERR;
        $this->width    = max(10, $width);
        $this->isTty    = $isTty ?? stream_isatty($this->stream);
        $this->useColor = $useColor ?? $this->detectColorSupport();
    }

    public function start(int $total): void
    {
        $this->total   = max(0, $total);
        $this->current = 0;

        $this->render();
    }

    public function advance(string $file): void
    {
        $this->current = min($this->total, $this->current + 1);

        $this->render();
    }

    public function finish(): void
    {
        if (! $this->isTty) {
            return;
        }

        if ($this->total > 0) {
            $this->current = $this->total;
            $this->render();
        }

        fprintf($this->stream, PHP_EOL);
        fflush($this->stream);
    }

    private function render(): void
    {
        if (! $this->isTty) {
            return;
        }

        $percent = $this->total > 0
            ? (int) (($this->current / $this->total) * 100)
            : 100;
        $filled  = $this->total > 0
            ? (int) (($this->current / $this->total) * $this->width)
            : $this->width;
        $bar     = $this->color(
            str_repeat('=', $filled),
            '32'
        ) . $this->color(str_repeat('-', $this->width - $filled), '90');
        $status  = $this->color('Analyzing', '36');

        fprintf(
            $this->stream,
            "\r%s [%s] %3d%% %d/%d",
            $status,
            $bar,
            $percent,
            $this->current,
            $this->total
        );
        fflush($this->stream);
    }

    private function detectColorSupport(): bool
    {
        if (getenv('NO_COLOR') !== false) {
            return false;
        }

        if (getenv('FORCE_COLOR') !== false) {
            return true;
        }

        return stream_isatty($this->stream);
    }

    private function color(string $value, string $code): string
    {
        if (! $this->useColor || $value === '') {
            return $value;
        }

        return "\033[" . $code . 'm' . $value . "\033[0m";
    }
}
