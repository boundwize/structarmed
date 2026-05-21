<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Cli;

use Boundwize\StructArmed\Cli\ColorSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function fopen;
use function getenv;
use function putenv;

#[CoversClass(ColorSupport::class)]
final class ColorSupportTest extends TestCase
{
    public function testDetectReturnsFalseWhenNoColorIsSet(): void
    {
        $this->withEnvironment(
            noColor: '1',
            forceColor: null,
            callback: function (): void {
                $this->assertFalse(ColorSupport::detect());
            }
        );
    }

    public function testDetectReturnsFalseWhenNoColorTakesPrecedenceOverForceColor(): void
    {
        $this->withEnvironment(
            noColor: '1',
            forceColor: '1',
            callback: function (): void {
                $this->assertFalse(ColorSupport::detect());
            }
        );
    }

    public function testDetectReturnsTrueWhenForceColorIsSet(): void
    {
        $this->withEnvironment(
            noColor: null,
            forceColor: '1',
            callback: function (): void {
                $this->assertTrue(ColorSupport::detect());
            }
        );
    }

    public function testDetectReturnsTrueOnGithubActions(): void
    {
        $this->withEnvironment(
            noColor: null,
            forceColor: null,
            callback: function (): void {
                $previousGithubActions = getenv('GITHUB_ACTIONS');
                putenv('GITHUB_ACTIONS=true');

                try {
                    $this->assertTrue(ColorSupport::detect());
                } finally {
                    putenv(
                        $previousGithubActions === false
                            ? 'GITHUB_ACTIONS'
                            : 'GITHUB_ACTIONS=' . $previousGithubActions
                    );
                }
            }
        );
    }

    public function testDetectFallsBackToStreamIsatty(): void
    {
        $this->withEnvironment(
            noColor: null,
            forceColor: null,
            callback: function (): void {
                $stream = fopen('php://temp', 'w+');
                $this->assertIsResource($stream);
                $this->assertFalse(ColorSupport::detect($stream));
            }
        );
    }

    public function testWrapReturnsValueUnchangedWhenColorDisabled(): void
    {
        $this->assertSame('hello', ColorSupport::wrap('hello', '91', false));
    }

    public function testWrapReturnsEmptyStringUnchanged(): void
    {
        $this->assertSame('', ColorSupport::wrap('', '91', true));
    }

    public function testWrapAddsAnsiCodesWhenColorEnabled(): void
    {
        $this->assertSame("\033[91mhello\033[0m", ColorSupport::wrap('hello', '91', true));
    }

    /**
     * @param callable(): void $callback
     */
    private function withEnvironment(?string $noColor, ?string $forceColor, callable $callback): void
    {
        $previousNoColor    = getenv('NO_COLOR');
        $previousForceColor = getenv('FORCE_COLOR');

        $this->setEnvironment('NO_COLOR', $noColor);
        $this->setEnvironment('FORCE_COLOR', $forceColor);

        try {
            $callback();
        } finally {
            $this->setEnvironment('NO_COLOR', $previousNoColor === false ? null : $previousNoColor);
            $this->setEnvironment('FORCE_COLOR', $previousForceColor === false ? null : $previousForceColor);
        }
    }

    private function setEnvironment(string $name, ?string $value): void
    {
        putenv($value === null ? $name : $name . '=' . $value);
    }
}
