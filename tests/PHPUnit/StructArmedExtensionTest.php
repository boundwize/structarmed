<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\PHPUnit;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Exception\ViolationsFoundException;
use Boundwize\StructArmed\PHPUnit\StructArmedExtension;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeFinalRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use ReflectionClass;

use function bin2hex;
use function chdir;
use function file_put_contents;
use function getcwd;
use function mkdir;
use function random_bytes;
use function tempnam;

#[CoversClass(StructArmedExtension::class)]
final class StructArmedExtensionTest extends TestCase
{
    public function testBootstrapPrintsPassingReport(): void
    {
        $configPath = $this->writeConfig('return ' . Architecture::class . '::define();');

        $this->expectOutputRegex('/No violations found/');

        (new StructArmedExtension())->bootstrap(
            $this->configuration(),
            new Facade(),
            ParameterCollection::fromArray(['config' => $configPath])
        );
    }

    public function testBootstrapDiscoversConfigWhenParameterIsMissing(): void
    {
        $basePath     = $this->makeTempProjectConfig('return ' . Architecture::class . '::define();');
        $previousPath = getcwd();
        $this->assertIsString($previousPath);

        chdir($basePath);

        try {
            $this->expectOutputRegex('/No violations found/');

            (new StructArmedExtension())->bootstrap(
                $this->configuration(),
                new Facade(),
                ParameterCollection::fromArray([])
            );
        } finally {
            chdir($previousPath);
        }
    }

    public function testBootstrapThrowsWhenViolationsAreFound(): void
    {
        $configPath = $this->writeConfig(
            'return ' . Architecture::class . "::define()\n"
            . "    ->layer('Domain', 'tests/Fixtures/sample/src/Domain/')\n"
            . "    ->rule('must_be_final', new " . MustBeFinalRule::class . "('Domain'));"
        );

        $this->expectException(ViolationsFoundException::class);
        $this->expectExceptionMessage('StructArmed found');
        $this->expectOutputRegex('/Found \d+ violation/');

        (new StructArmedExtension())->bootstrap(
            $this->configuration(),
            new Facade(),
            ParameterCollection::fromArray(['config' => $configPath])
        );
    }

    private function configuration(): Configuration
    {
        return (new ReflectionClass(Configuration::class))->newInstanceWithoutConstructor();
    }

    private function writeConfig(string $body): string
    {
        $path = tempnam('/private/tmp', 'structarmed-extension-');
        $this->assertIsString($path);
        file_put_contents($path, "<?php\n\n" . $body . "\n");

        return $path;
    }

    private function makeTempProjectConfig(string $body): string
    {
        $basePath = '/private/tmp/structarmed-extension-' . bin2hex(random_bytes(6));
        mkdir($basePath);
        file_put_contents($basePath . '/structarmed.php', "<?php\n\n" . $body . "\n");

        return $basePath;
    }
}
