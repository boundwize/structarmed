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

            $violations = (new Psr1SymbolsOrSideEffectsRule(['src/']))->evaluateProjectAll(
                $basePath,
                Architecture::define()
            );

            $this->assertCount(1, $violations);
            $this->assertSame(2, $violations[0]->line);
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

            $violations = (new Psr1SymbolsOrSideEffectsRule(['src/']))->evaluateProjectAll(
                $basePath,
                Architecture::define()
            );

            $this->assertSame([], $violations);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testViolatesSideEffectInsideDeclareBlockNextToSymbol(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents(
                $basePath . '/src/Foo.php',
                "<?php\n\ndeclare(ticks=1) {\n    echo 'side effect';\n}\n\nfinal class Foo {}\n"
            );

            $violations = (new Psr1SymbolsOrSideEffectsRule(['src/']))->evaluateProjectAll(
                $basePath,
                Architecture::define()
            );

            $this->assertCount(1, $violations);
            $this->assertSame(4, $violations[0]->line);
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

            $violations = (new Psr1SymbolsOrSideEffectsRule(['src/']))->evaluateProjectAll(
                $basePath,
                Architecture::define()
            );

            $this->assertSame([], $violations);
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

            $violations = (new Psr1SymbolsOrSideEffectsRule(['src/']))->evaluateProjectAll(
                $basePath,
                Architecture::define()
            );

            $this->assertCount(1, $violations);
            $this->assertSame(4, $violations[0]->line);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testPassesFileWithNamespaceConstantAndClass(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents(
                $basePath . '/src/Foo.php',
                <<<'PHP'
                <?php

                namespace App;

                const VERSION = '1.0';

                final class Foo {}
                PHP
            );

            $violations = (new Psr1SymbolsOrSideEffectsRule(['src/']))->evaluateProjectAll(
                $basePath,
                Architecture::define()
            );

            $this->assertSame([], $violations);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testPassesDefineConstantNextToClass(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents(
                $basePath . '/src/Foo.php',
                "<?php\ndefine('APP_VERSION', '1.2.3');\nclass Bootstrap {}\n"
            );

            $violations = (new Psr1SymbolsOrSideEffectsRule(['src/']))->evaluateProjectAll(
                $basePath,
                Architecture::define()
            );

            $this->assertSame([], $violations);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testPassesDefinedGuardedDefineNextToClass(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents(
                $basePath . '/src/Foo.php',
                "<?php\nif (! defined('APP_VERSION')) {\n    define('APP_VERSION', '1.2.3');\n}\nclass Bootstrap {}\n"
            );

            $violations = (new Psr1SymbolsOrSideEffectsRule(['src/']))->evaluateProjectAll(
                $basePath,
                Architecture::define()
            );

            $this->assertSame([], $violations);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testViolatesEchoNextToDefineAndClass(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents(
                $basePath . '/src/Foo.php',
                "<?php\ndefine('APP_VERSION', '1.2.3');\necho 'x';\nclass Bootstrap {}\n"
            );

            $violations = (new Psr1SymbolsOrSideEffectsRule(['src/']))->evaluateProjectAll(
                $basePath,
                Architecture::define()
            );

            $this->assertCount(1, $violations);
            $this->assertSame(3, $violations[0]->line);
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

            $violations = (new Psr1SymbolsOrSideEffectsRule(['src/']))->evaluateProjectAll(
                $basePath,
                Architecture::define()
            );

            $this->assertCount(1, $violations);
            $this->assertSame(2, $violations[0]->line);
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

            $violations = (new Psr1SymbolsOrSideEffectsRule(['src/']))->evaluateProjectAll(
                $basePath,
                Architecture::define()
            );

            $this->assertCount(1, $violations);
            $this->assertSame(2, $violations[0]->line);
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

            $violations = (new Psr1SymbolsOrSideEffectsRule(['src/']))->evaluateProjectAll(
                $basePath,
                Architecture::define(),
                [$basePath . '/src']
            );

            $this->assertSame([], $violations);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testSkipsFileMatchingGlobSkipPath(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Foo.php', "<?php\nini_set('memory_limit', '1G');\nclass Foo {}\n");

            $violations = (new Psr1SymbolsOrSideEffectsRule(['src/']))->evaluateProjectAll(
                $basePath,
                Architecture::define(),
                ['src/*.php']
            );

            $this->assertSame([], $violations);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testDoesNotSkipFileWhenSkipPathDoesNotMatch(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Foo.php', "<?php\nini_set('memory_limit', '1G');\nclass Foo {}\n");

            $violations = (new Psr1SymbolsOrSideEffectsRule(['src/']))->evaluateProjectAll(
                $basePath,
                Architecture::define(),
                ['tests']
            );

            $this->assertCount(1, $violations);
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

            $violations = (new Psr1SymbolsOrSideEffectsRule(['src/']))->evaluateProjectAll(
                $basePath,
                Architecture::define(),
                ['src']
            );

            $this->assertSame([], $violations);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testEvaluateProjectReturnsFirstViolation(): void
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
            $this->assertStringContainsString('Foo.php', $violation->message);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testReturnsAllViolationsWhenMultipleFilesViolate(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Foo.php', "<?php\nini_set('memory_limit', '1G');\nclass Foo {}\n");
            file_put_contents($basePath . '/src/Bar.php', "<?php\nini_set('display_errors', '1');\nclass Bar {}\n");

            $violations = (new Psr1SymbolsOrSideEffectsRule(['src/']))->evaluateProjectAll(
                $basePath,
                Architecture::define()
            );

            $this->assertCount(2, $violations);
        } finally {
            unlink($basePath . '/src/Foo.php');
            unlink($basePath . '/src/Bar.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testAbsoluteSourcePathIsNotPrefixedWithBasePath(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Foo.php', "<?php\nini_set('memory_limit', '1G');\nclass Foo {}\n");

            $violations = (new Psr1SymbolsOrSideEffectsRule([$basePath . '/src']))->evaluateProjectAll(
                '/some/unrelated/base',
                Architecture::define()
            );

            $this->assertCount(1, $violations);
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
