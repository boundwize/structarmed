<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Cli;

use Boundwize\StructArmed\Cli\ColorSupport;
use Boundwize\StructArmed\Tests\Support\InMemoryStreamTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function getenv;
use function putenv;

#[CoversClass(ColorSupport::class)]
final class ColorSupportTest extends TestCase
{
    use InMemoryStreamTrait;

    private const MANAGED_ENVIRONMENT_VARIABLES = [
        'NO_COLOR',
        'FORCE_COLOR',
        'CLICOLOR_FORCE',
        'CLICOLOR',
        'GITHUB_ACTIONS',
        'GITLAB_CI',
        'CIRCLECI',
        'TRAVIS',
        'BUILDKITE',
        'APPVEYOR',
        'TF_BUILD',
        'TERM',
        'ANSICON',
        'ConEmuANSI',
    ];

    public function testDetectReturnsFalseWhenNoColorIsSet(): void
    {
        $this->withEnvironment(
            ['NO_COLOR' => '1'],
            function (): void {
                $this->assertFalse(ColorSupport::detect());
            }
        );
    }

    public function testDetectReturnsFalseWhenNoColorTakesPrecedenceOverForceColor(): void
    {
        $this->withEnvironment(
            ['NO_COLOR' => '1', 'FORCE_COLOR' => '1'],
            function (): void {
                $this->assertFalse(ColorSupport::detect());
            }
        );
    }

    public function testDetectReturnsTrueWhenForceColorIsSet(): void
    {
        $this->withEnvironment(
            ['FORCE_COLOR' => '1'],
            function (): void {
                $this->assertTrue(ColorSupport::detect());
            }
        );
    }

    public function testDetectReturnsTrueWhenCliColorForceIsSet(): void
    {
        $this->withEnvironment(
            ['CLICOLOR_FORCE' => '1'],
            function (): void {
                $this->assertTrue(ColorSupport::detect());
            }
        );
    }

    public function testDetectIgnoresCliColorForceZero(): void
    {
        $this->withEnvironment(
            ['CLICOLOR_FORCE' => '0'],
            function (): void {
                $stream = $this->openMemoryStream();

                $this->assertFalse(ColorSupport::detect($stream));
            }
        );
    }

    public function testDetectReturnsFalseWhenCliColorIsZero(): void
    {
        $this->withEnvironment(
            ['CLICOLOR' => '0'],
            function (): void {
                $this->assertFalse(ColorSupport::detect());
            }
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideCiEnvironmentVariable(): iterable
    {
        yield 'GitHub Actions' => ['GITHUB_ACTIONS'];
        yield 'GitLab CI' => ['GITLAB_CI'];
        yield 'CircleCI' => ['CIRCLECI'];
        yield 'Travis CI' => ['TRAVIS'];
        yield 'Buildkite' => ['BUILDKITE'];
        yield 'AppVeyor' => ['APPVEYOR'];
        yield 'Azure Pipelines' => ['TF_BUILD'];
    }

    #[DataProvider('provideCiEnvironmentVariable')]
    public function testDetectReturnsTrueOnCiEnvironment(string $ciEnvironmentVariable): void
    {
        $this->withEnvironment(
            [$ciEnvironmentVariable => 'true'],
            function (): void {
                $this->assertTrue(ColorSupport::detect());
            }
        );
    }

    public function testDetectReturnsFalseOnDumbTerminal(): void
    {
        $this->withEnvironment(
            ['TERM' => 'dumb'],
            function (): void {
                $this->assertFalse(ColorSupport::detect());
            }
        );
    }

    public function testDetectReturnsTrueWhenAnsiconIsSet(): void
    {
        $this->withEnvironment(
            ['ANSICON' => '1'],
            function (): void {
                $this->assertTrue(ColorSupport::detect());
            }
        );
    }

    public function testDetectReturnsTrueWhenConEmuAnsiIsOn(): void
    {
        $this->withEnvironment(
            ['ConEmuANSI' => 'ON'],
            function (): void {
                $this->assertTrue(ColorSupport::detect());
            }
        );
    }

    public function testDetectIgnoresConEmuAnsiOff(): void
    {
        $this->withEnvironment(
            ['ConEmuANSI' => 'OFF'],
            function (): void {
                $stream = $this->openMemoryStream();

                $this->assertFalse(ColorSupport::detect($stream));
            }
        );
    }

    public function testDetectFallsBackToStreamIsatty(): void
    {
        $this->withEnvironment(
            [],
            function (): void {
                $stream = $this->openMemoryStream();

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
     * @param array<string, string> $environment
     * @param callable(): void      $callback
     */
    private function withEnvironment(array $environment, callable $callback): void
    {
        $previousValues = [];

        foreach (self::MANAGED_ENVIRONMENT_VARIABLES as $name) {
            $previousValues[$name] = getenv($name);

            $this->setEnvironment($name, $environment[$name] ?? null);
        }

        try {
            $callback();
        } finally {
            foreach ($previousValues as $name => $previousValue) {
                $this->setEnvironment($name, $previousValue === false ? null : $previousValue);
            }
        }
    }

    private function setEnvironment(string $name, ?string $value): void
    {
        putenv($value === null ? $name : $name . '=' . $value);
    }
}
