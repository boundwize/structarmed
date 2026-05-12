<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\File;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Rules\File\PhpFileFinder;
use Boundwize\StructArmed\Rule\Rules\File\Psr1SymbolsOrSideEffectsRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(PhpFileFinder::class)]
#[CoversClass(Psr1SymbolsOrSideEffectsRule::class)]
final class Psr1SymbolsOrSideEffectsRuleTest extends TestCase
{
    public function testViolatesFileWithSymbolsAndSideEffects(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Foo.php', "<?php\nini_set('memory_limit', '1G');\nclass Foo {}\n");

            $violation = (new Psr1SymbolsOrSideEffectsRule(['src/']))->evaluateProject(
                $basePath,
                Architecture::define()
            );

            $this->assertInstanceOf(RuleViolation::class, $violation);
            $this->assertSame(2, $violation->line);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testPassesConditionalDeclaration(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents(
                $basePath . '/src/Foo.php',
                "<?php\nif (! function_exists('foo')) {\n    function foo(): void {}\n}\n"
            );

            $violation = (new Psr1SymbolsOrSideEffectsRule(['src/']))->evaluateProject(
                $basePath,
                Architecture::define()
            );

            $this->assertNotInstanceOf(RuleViolation::class, $violation);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testPassesParseErrorsAndFilesWithOnlySideEffects(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Broken.php', "<?php\nclass Broken {\n");
            file_put_contents($basePath . '/src/SideEffect.php', "<?php\nini_set('memory_limit', '1G');\n");

            $violation = (new Psr1SymbolsOrSideEffectsRule(['src/']))->evaluateProject(
                $basePath,
                Architecture::define()
            );

            $this->assertNotInstanceOf(RuleViolation::class, $violation);
        } finally {
            unlink($basePath . '/src/Broken.php');
            unlink($basePath . '/src/SideEffect.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testViolatesNamespacedFileWithSymbolsAndSideEffects(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents(
                $basePath . '/src/Foo.php',
                "<?php\nnamespace App;\nuse DateTimeImmutable;\necho 'x';\nclass Foo {}\n"
            );

            $violation = (new Psr1SymbolsOrSideEffectsRule(['src/']))->evaluateProject(
                $basePath,
                Architecture::define()
            );

            $this->assertInstanceOf(RuleViolation::class, $violation);
            $this->assertSame(4, $violation->line);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testViolatesConditionalSideEffectNextToSymbol(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents(
                $basePath . '/src/Foo.php',
                "<?php\nif (true) {\n    echo 'x';\n}\nclass Foo {}\n"
            );

            $violation = (new Psr1SymbolsOrSideEffectsRule(['src/']))->evaluateProject(
                $basePath,
                Architecture::define()
            );

            $this->assertInstanceOf(RuleViolation::class, $violation);
            $this->assertSame(2, $violation->line);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testViolatesIfElseDeclarationAsSideEffectNextToSymbol(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents(
                $basePath . '/src/Foo.php',
                "<?php\nif (true) {\n    class One {}\n} else {\n    class Two {}\n}\nclass Foo {}\n"
            );

            $violation = (new Psr1SymbolsOrSideEffectsRule(['src/']))->evaluateProject(
                $basePath,
                Architecture::define()
            );

            $this->assertInstanceOf(RuleViolation::class, $violation);
            $this->assertSame(2, $violation->line);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testSkipsFileMatchingAbsoluteSkipPath(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Foo.php', "<?php\nini_set('memory_limit', '1G');\nclass Foo {}\n");

            $violation = (new Psr1SymbolsOrSideEffectsRule(['src/']))->evaluateProject(
                $basePath,
                Architecture::define(),
                [$basePath . '/src']
            );

            $this->assertNotInstanceOf(RuleViolation::class, $violation);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testSkipsFileMatchingRelativeSkipPath(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Foo.php', "<?php\nini_set('memory_limit', '1G');\nclass Foo {}\n");

            $violation = (new Psr1SymbolsOrSideEffectsRule(['src/']))->evaluateProject(
                $basePath,
                Architecture::define(),
                ['src']
            );

            $this->assertNotInstanceOf(RuleViolation::class, $violation);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    private function makeTempDir(): string
    {
        $path = sys_get_temp_dir() . '/structarmed-psr1-' . bin2hex(random_bytes(6));
        mkdir($path);

        return $path;
    }
}
