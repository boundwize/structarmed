<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests;

use Boundwize\StructArmed\Architecture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function realpath;

#[CoversClass(Architecture::class)]
final class ArchitectureImportTest extends TestCase
{
    public function testImportAppliesConfiguratorCallable(): void
    {
        $architecture = Architecture::define()
            ->import(__DIR__ . '/Fixtures/imports/layers.php');

        $this->assertSame(
            ['Domain' => 'src/Domain/', 'Application' => 'src/Application/'],
            $architecture->getLayers()
        );
        $this->assertSame(
            [(string) realpath(__DIR__ . '/Fixtures/imports/layers.php')],
            $architecture->getImportedFiles()
        );
    }

    public function testImportIsAppliedAtMostOncePerFile(): void
    {
        $architecture = Architecture::define()
            ->import(__DIR__ . '/Fixtures/imports/skips.php')
            ->import(__DIR__ . '/Fixtures/imports/skips.php');

        $this->assertSame(['tests/Fixtures/'], $architecture->getSkipPaths());
        $this->assertCount(1, $architecture->getImportedFiles());
    }

    public function testImportSupportsNestedImports(): void
    {
        $architecture = Architecture::define()
            ->import(__DIR__ . '/Fixtures/imports/nested.php');

        $this->assertSame(
            [
                'Domain'         => 'src/Domain/',
                'Application'    => 'src/Application/',
                'Infrastructure' => 'src/Infrastructure/',
            ],
            $architecture->getLayers()
        );
        $this->assertSame(
            [
                (string) realpath(__DIR__ . '/Fixtures/imports/nested.php'),
                (string) realpath(__DIR__ . '/Fixtures/imports/layers.php'),
            ],
            $architecture->getImportedFiles()
        );
    }

    public function testImportsAcceptsStringAndArray(): void
    {
        $architecture = Architecture::define()
            ->imports(__DIR__ . '/Fixtures/imports/layers.php')
            ->imports([__DIR__ . '/Fixtures/imports/skips.php']);

        $this->assertCount(2, $architecture->getImportedFiles());
    }

    public function testImportOfFileImportingItselfIsAppliedOnce(): void
    {
        $architecture = Architecture::define()
            ->import(__DIR__ . '/Fixtures/imports/self-importing.php');

        $this->assertSame(
            ['SelfImporting' => 'src/SelfImporting/'],
            $architecture->getLayers()
        );
        $this->assertSame(
            [(string) realpath(__DIR__ . '/Fixtures/imports/self-importing.php')],
            $architecture->getImportedFiles()
        );
    }

    public function testCircularImportsDoNotLoop(): void
    {
        $architecture = Architecture::define()
            ->import(__DIR__ . '/Fixtures/imports/circular-a.php');

        $this->assertSame(
            [
                'CircularB' => 'src/CircularB/',
                'CircularA' => 'src/CircularA/',
            ],
            $architecture->getLayers()
        );
        $this->assertSame(
            [
                (string) realpath(__DIR__ . '/Fixtures/imports/circular-a.php'),
                (string) realpath(__DIR__ . '/Fixtures/imports/circular-b.php'),
            ],
            $architecture->getImportedFiles()
        );
    }

    public function testImportThrowsWhenFileNotFound(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('was not found');

        Architecture::define()->import(__DIR__ . '/Fixtures/imports/missing.php');
    }

    public function testImportThrowsWhenFileDoesNotReturnCallable(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must return a callable');

        Architecture::define()->import(__DIR__ . '/Fixtures/imports/not-callable.php');
    }
}
