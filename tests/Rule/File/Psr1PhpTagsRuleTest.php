<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\File;

use Boundwize\StructArmed\Analyser\FileAnalysis;
use Boundwize\StructArmed\Analyser\FileAnalysisProvider;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Rules\File\PhpFileFinder;
use Boundwize\StructArmed\Rule\Rules\File\Psr1PhpTagsRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function realpath;
use function rmdir;
use function str_replace;
use function symlink;
use function sys_get_temp_dir;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(PhpFileFinder::class)]
#[CoversClass(Psr1PhpTagsRule::class)]
final class Psr1PhpTagsRuleTest extends TestCase
{
    public function testViolatesShortOpenTag(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Foo.php', '<? echo "x";');

            $violations = (new Psr1PhpTagsRule(['src/']))->evaluateProjectAll($basePath, Architecture::define());

            $this->assertCount(1, $violations);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testPassesLongAndEchoTags(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Foo.php', "<?php\nclass Foo {}\n?>\n<?= 'x';");

            $violations = (new Psr1PhpTagsRule(['src/']))->evaluateProjectAll($basePath, Architecture::define());

            $this->assertSame([], $violations);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testIgnoresPhpTagsInsideStrings(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents(
                $basePath . '/src/Foo.php',
                "<?php\n\$template = \"<?php\\n\\nclass Generated {}\";\n"
            );

            $violations = (new Psr1PhpTagsRule(['src/']))->evaluateProjectAll($basePath, Architecture::define());

            $this->assertSame([], $violations);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testViolatesUpperCaseLongOpenTag(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Foo.php', '<?PHP echo "x";');

            $violations = (new Psr1PhpTagsRule(['src/']))->evaluateProjectAll($basePath, Architecture::define());

            $this->assertCount(1, $violations);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testPassesWhenNoPhpFilesAreFound(): void
    {
        $basePath = $this->makeTempDir();

        try {
            $violations = (new Psr1PhpTagsRule(['missing/']))->evaluateProjectAll($basePath, Architecture::define());

            $this->assertSame([], $violations);
        } finally {
            rmdir($basePath);
        }
    }

    public function testIgnoresNonPhpFilesInSourcePath(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/readme.md', '<? echo "x";');

            $violations = (new Psr1PhpTagsRule(['src/']))->evaluateProjectAll($basePath, Architecture::define());

            $this->assertSame([], $violations);
        } finally {
            unlink($basePath . '/src/readme.md');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testPhpFileFinderIgnoresDirectorySymlinksAndReusesCanonicalAnalysis(): void
    {
        $basePath       = $this->makeTempDir();
        $linkedBasePath = $basePath . '-link';

        try {
            mkdir($basePath . '/src');
            mkdir($basePath . '/src/Docs');
            mkdir($basePath . '/LinkedDirectory');
            symlink($basePath . '/LinkedDirectory', $basePath . '/src/LinkedDirectory');
            file_put_contents($basePath . '/src/Docs/readme.md', '<? echo "x";');
            file_put_contents($basePath . '/src/Foo.php', '<?php echo "x";');
            symlink($basePath, $linkedBasePath);

            $files = (new PhpFileFinder(['src/']))->files($basePath);

            $this->assertCount(1, $files);
            $this->assertStringEndsWith('/src/Foo.php', str_replace('\\', '/', $files[0]));

            $canonicalFile = realpath($basePath . '/src/Foo.php');
            $this->assertIsString($canonicalFile);

            $fileAnalysis         = new FileAnalysis(
                file: $canonicalFile,
                hasUtf8Bom: false,
                hasValidUtf8: true,
                invalidPhpTagLine: 37,
                hasValidAst: true,
                declaresSymbols: false,
                hasSideEffects: true,
                sideEffectLine: 1,
            );
            $fileAnalysisProvider = new FileAnalysisProvider([$canonicalFile => $fileAnalysis]);

            $violations = (new Psr1PhpTagsRule(['src/']))->evaluateProjectAllWithProvider(
                $linkedBasePath,
                Architecture::define(),
                $fileAnalysisProvider,
            );

            $this->assertCount(1, $violations);
            $this->assertSame(37, $violations[0]->line);
            $this->assertStringStartsWith($linkedBasePath, $violations[0]->file);
        } finally {
            DIRECTORY_SEPARATOR === '\\' ? rmdir($linkedBasePath) : unlink($linkedBasePath);
            unlink($basePath . '/src/Foo.php');
            unlink($basePath . '/src/Docs/readme.md');
            DIRECTORY_SEPARATOR === '\\'
                ? rmdir($basePath . '/src/LinkedDirectory')
                : unlink($basePath . '/src/LinkedDirectory');
            rmdir($basePath . '/src/Docs');
            rmdir($basePath . '/src');
            rmdir($basePath . '/LinkedDirectory');
            rmdir($basePath);
        }
    }

    public function testEvaluateProjectReturnsFirstViolation(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Foo.php', '<? echo "x";');

            $violation = (new Psr1PhpTagsRule(['src/']))->evaluateProject($basePath, Architecture::define());

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
            file_put_contents($basePath . '/src/Foo.php', '<? echo "x";');
            file_put_contents($basePath . '/src/Bar.php', '<? echo "y";');

            $violations = (new Psr1PhpTagsRule(['src/']))->evaluateProjectAll($basePath, Architecture::define());

            $this->assertCount(2, $violations);
        } finally {
            unlink($basePath . '/src/Foo.php');
            unlink($basePath . '/src/Bar.php');
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
