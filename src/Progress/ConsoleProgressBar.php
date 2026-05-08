<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Progress;

use function basename;
use function fflush;
use function fprintf;
use function getenv;
use function max;
use function min;
use function rtrim;
use function str_pad;
use function str_repeat;
use function stream_isatty;
use function strlen;
use function substr;

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

    /**
     * @param resource|null $stream
     */
    public function __construct(mixed $stream = null, int $width = 28, ?bool $useColor = null)
    {
        $this->stream   = $stream ?? STDERR;
        $this->width    = max(10, $width);
        $this->useColor = $useColor ?? $this->detectColorSupport();
    }

    public function start(int $total): void
    {
        $this->total   = max(0, $total);
        $this->current = 0;

        $this->render('');
    }

    public function advance(string $file): void
    {
        $this->current = min($this->total, $this->current + 1);

        $this->render($file);
    }

    public function finish(): void
    {
        if ($this->total > 0) {
            $this->current = $this->total;
            $this->render('');
        }

        fprintf($this->stream, PHP_EOL);
        fflush($this->stream);
    }

    private function render(string $file): void
    {
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
        $label   = $file !== '' ? ' ' . $this->truncate(basename($file), 30) : '';
        $status  = $this->color('Analyzing', '36');

        fprintf(
            $this->stream,
            "\r%s [%s] %3d%% %d/%d%s",
            $status,
            $bar,
            $percent,
            $this->current,
            $this->total,
            $label
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

    private function truncate(string $value, int $length): string
    {
        $value = rtrim($value);

        if (strlen($value) <= $length) {
            return $value;
        }

        return str_pad(substr($value, 0, $length - 3), $length - 3) . '...';
    }
}
