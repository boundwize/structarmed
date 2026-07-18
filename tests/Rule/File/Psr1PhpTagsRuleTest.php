<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\File;

use Boundwize\StructArmed\Analyser\FileAnalysis;
use Boundwize\StructArmed\Analyser\FileAnalysisProvider;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\FixableInterface;
use Boundwize\StructArmed\Rule\Rules\File\PhpFileFinder;
use Boundwize\StructArmed\Rule\Rules\File\Psr1PhpTagsRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Util\InlineHtmlOpeningTagMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function dirname;
use function escapeshellarg;
use function exec;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function mkdir;
use function random_bytes;
use function realpath;
use function rmdir;
use function rtrim;
use function str_replace;
use function symlink;
use function sys_get_temp_dir;
use function unlink;
use function var_export;

use const DIRECTORY_SEPARATOR;
use const PHP_BINARY;

#[CoversClass(PhpFileFinder::class)]
#[CoversClass(Psr1PhpTagsRule::class)]
#[CoversClass(InlineHtmlOpeningTagMatcher::class)]
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

    public function testViolatesEchoImmediatelyAfterShortOpenTag(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Foo.php', "<?echo 'test';?>");
            $command = escapeshellarg(PHP_BINARY)
                . ' -d short_open_tag=1 '
                . escapeshellarg($basePath . '/src/Foo.php');

            exec($command, $output, $exitCode);

            $this->assertSame(0, $exitCode);
            $this->assertSame(['test'], $output);

            $psr1PhpTagsRule = new Psr1PhpTagsRule(['src/']);
            $violations      = $psr1PhpTagsRule->evaluateProjectAll($basePath, Architecture::define());

            $this->assertCount(1, $violations);
            $this->assertSame(1, $violations[0]->line);
            $this->assertTrue($psr1PhpTagsRule->fix($violations[0]));
            $this->assertSame("<?php echo 'test';?>", file_get_contents($basePath . '/src/Foo.php'));

            $output = [];
            exec($command, $output, $exitCode);

            $this->assertSame(0, $exitCode);
            $this->assertSame(['test'], $output);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testFixesShortOpenTagFollowedByCodeWhenShortOpenTagEnabled(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Foo.php', '<?echo "hi";');

            $fixedContent = $this->fixWithShortOpenTagEnabled($basePath);

            // A bare `<?` short open tag tokenizes without the trailing character,
            // so replacing it with `<?php` alone would yield `<?phpecho "hi";`.
            $this->assertSame('<?php echo "hi";', $fixedContent);

            $command = escapeshellarg(PHP_BINARY)
                . ' -d short_open_tag=1 '
                . escapeshellarg($basePath . '/src/Foo.php');
            exec($command, $output, $exitCode);

            $this->assertSame(0, $exitCode);
            $this->assertSame(['hi'], $output);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testFixesShortOpenTagFollowedByVariableWhenShortOpenTagEnabled(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Foo.php', '<?$value = 1; echo $value;');

            $fixedContent = $this->fixWithShortOpenTagEnabled($basePath);

            $this->assertSame('<?php $value = 1; echo $value;', $fixedContent);

            $command = escapeshellarg(PHP_BINARY)
                . ' -d short_open_tag=1 -l '
                . escapeshellarg($basePath . '/src/Foo.php');
            exec($command, $output, $exitCode);

            $this->assertSame(0, $exitCode);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testViolatesAssignmentUsingShortOpenTag(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Foo.php', '<? $value = 1; ?>');

            $violations = (new Psr1PhpTagsRule(['src/']))->evaluateProjectAll($basePath, Architecture::define());

            $this->assertCount(1, $violations);
            $this->assertSame(1, $violations[0]->line);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testViolatesCommentPrefixedShortOpenTag(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Foo.php', "<? /* comment */ echo 'x'; ?>");

            $violations = (new Psr1PhpTagsRule(['src/']))->evaluateProjectAll($basePath, Architecture::define());

            $this->assertCount(1, $violations);
            $this->assertSame(1, $violations[0]->line);
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

    public function testPassesXmlProcessingInstructionsInInlineHtml(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            $contents = <<<'PHP'
                <?php

                header('Content-Type: application/xml');

                ?>
                <?xml version="1.0" encoding="UTF-8"?>
                <?xml-stylesheet type="text/xsl" href="sitemap.xsl"?>
                <urlset />
                PHP;
            file_put_contents($basePath . '/src/sitemap.php', $contents);

            $psr1PhpTagsRule = new Psr1PhpTagsRule(['src/']);
            $violations      = $psr1PhpTagsRule->evaluateProjectAll($basePath, Architecture::define());

            $this->assertSame([], $violations);
            $this->assertFalse($psr1PhpTagsRule->fix(new RuleViolation(
                message:   'File must use only valid PHP tags',
                file:      $basePath . '/src/sitemap.php',
                line:      6,
                className: '',
            )));
            $this->assertSame($contents, file_get_contents($basePath . '/src/sitemap.php'));
        } finally {
            unlink($basePath . '/src/sitemap.php');
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
        $basePath         = $this->makeTempDir();
        $linkedBasePath   = $basePath . '-link';
        $analysisBasePath = $basePath;

        try {
            mkdir($basePath . '/src');
            mkdir($basePath . '/src/Docs');
            mkdir($basePath . '/LinkedDirectory');
            symlink($basePath . '/LinkedDirectory', $basePath . '/src/LinkedDirectory');
            file_put_contents($basePath . '/src/Docs/readme.md', '<? echo "x";');
            file_put_contents($basePath . '/src/Foo.php', '<?php echo "x";');
            if (DIRECTORY_SEPARATOR !== '\\') {
                symlink($basePath, $linkedBasePath);
                $analysisBasePath = $linkedBasePath;
            }

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
                $analysisBasePath,
                Architecture::define(),
                $fileAnalysisProvider,
            );

            $this->assertCount(1, $violations);
            $this->assertSame(37, $violations[0]->line);
            $this->assertStringStartsWith(
                rtrim(str_replace('\\', '/', $analysisBasePath), '/') . '/',
                str_replace('\\', '/', $violations[0]->file),
            );
        } finally {
            if (DIRECTORY_SEPARATOR !== '\\') {
                unlink($linkedBasePath);
            }

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

    public function testIsFixable(): void
    {
        $this->assertInstanceOf(FixableInterface::class, new Psr1PhpTagsRule(['src/']));
    }

    public function testFixesInvalidPhpTagOnViolationLineOnly(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents(
                $basePath . '/src/Foo.php',
                '<?php $template = "<? echo string"; ?><? echo "x";'
            );

            $psr1PhpTagsRule = new Psr1PhpTagsRule(['src/']);
            $violation       = $psr1PhpTagsRule->evaluateProject($basePath, Architecture::define());

            $this->assertInstanceOf(RuleViolation::class, $violation);
            $this->assertSame(1, $violation->line);
            $this->assertTrue($psr1PhpTagsRule->fix($violation));
            $this->assertSame(
                '<?php $template = "<? echo string"; ?><?php echo "x";',
                file_get_contents($basePath . '/src/Foo.php')
            );
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testReportsAndFixesShortTagAfterMultilineInlineHtml(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents(
                $basePath . '/src/template.php',
                "<html>\n<body>\n<? echo \"Hello\"; ?>"
            );

            $psr1PhpTagsRule = new Psr1PhpTagsRule(['src/']);
            $violation       = $psr1PhpTagsRule->evaluateProject($basePath, Architecture::define());

            $this->assertInstanceOf(RuleViolation::class, $violation);
            $this->assertSame(3, $violation->line);
            $this->assertTrue($psr1PhpTagsRule->fix($violation));
            $this->assertSame(
                "<html>\n<body>\n<?php echo \"Hello\"; ?>",
                file_get_contents($basePath . '/src/template.php')
            );
        } finally {
            unlink($basePath . '/src/template.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testReportsAndFixesShortTagWithDirectFunctionCallAfterMultilineInlineHtml(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents(
                $basePath . '/src/template.php',
                "<html>\n<body>\n<? some_call(); ?>"
            );

            $psr1PhpTagsRule = new Psr1PhpTagsRule(['src/']);
            $violation       = $psr1PhpTagsRule->evaluateProject($basePath, Architecture::define());

            $this->assertInstanceOf(RuleViolation::class, $violation);
            $this->assertSame(3, $violation->line);
            $this->assertTrue($psr1PhpTagsRule->fix($violation));
            $this->assertSame(
                "<html>\n<body>\n<?php some_call(); ?>",
                file_get_contents($basePath . '/src/template.php')
            );
        } finally {
            unlink($basePath . '/src/template.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testReportsAndFixesEmptyShortTagAfterMultilineInlineHtml(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents(
                $basePath . '/src/template.php',
                "<html>\n<body>\n<? ?>"
            );

            $psr1PhpTagsRule = new Psr1PhpTagsRule(['src/']);
            $violation       = $psr1PhpTagsRule->evaluateProject($basePath, Architecture::define());

            $this->assertInstanceOf(RuleViolation::class, $violation);
            $this->assertSame(3, $violation->line);
            $this->assertTrue($psr1PhpTagsRule->fix($violation));
            $this->assertSame(
                "<html>\n<body>\n<?php ?>",
                file_get_contents($basePath . '/src/template.php')
            );
        } finally {
            unlink($basePath . '/src/template.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testIgnoresShortTagExampleInsideString(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            $contents = "<?php\n\necho \"this is example of invalid code: <? echo \\\"test\\\"; ?>\";";
            file_put_contents(
                $basePath . '/src/template.php',
                $contents
            );

            $psr1PhpTagsRule = new Psr1PhpTagsRule(['src/']);
            $violation       = $psr1PhpTagsRule->evaluateProject($basePath, Architecture::define());

            $this->assertNotInstanceOf(RuleViolation::class, $violation);
            $this->assertSame($contents, file_get_contents($basePath . '/src/template.php'));
        } finally {
            unlink($basePath . '/src/template.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testFixesUpperCaseLongOpenTag(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Foo.php', '<?PHP echo "x";');

            $psr1PhpTagsRule = new Psr1PhpTagsRule(['src/']);
            $violation       = $psr1PhpTagsRule->evaluateProject($basePath, Architecture::define());

            $this->assertInstanceOf(RuleViolation::class, $violation);
            $this->assertTrue($psr1PhpTagsRule->fix($violation));
            $this->assertSame('<?php echo "x";', file_get_contents($basePath . '/src/Foo.php'));
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testDoesNotFixValidPhpTag(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Foo.php', '<?php echo "x";');

            $psr1PhpTagsRule = new Psr1PhpTagsRule(['src/']);

            $this->assertFalse($psr1PhpTagsRule->fix(new RuleViolation(
                message:   'File must use only valid PHP tags',
                file:      $basePath . '/src/Foo.php',
                line:      1,
                className: '',
            )));
            $this->assertSame('<?php echo "x";', file_get_contents($basePath . '/src/Foo.php'));
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testDoesNotFixInvalidPhpTagFromDifferentLine(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Foo.php', "<?php\n?>\n<? echo \"x\";");

            $psr1PhpTagsRule = new Psr1PhpTagsRule(['src/']);

            $this->assertFalse($psr1PhpTagsRule->fix(new RuleViolation(
                message:   'File must use only valid PHP tags',
                file:      $basePath . '/src/Foo.php',
                line:      1,
                className: '',
            )));
            $this->assertSame("<?php\n?>\n<? echo \"x\";", file_get_contents($basePath . '/src/Foo.php'));
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testDoesNotFixMissingFile(): void
    {
        $this->assertFalse((new Psr1PhpTagsRule(['src/']))->fix(new RuleViolation(
            message:   'File must use only valid PHP tags',
            file:      sys_get_temp_dir() . '/structarmed-missing-file-' . bin2hex(random_bytes(6)) . '.php',
            line:      1,
            className: '',
        )));
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

    /**
     * Runs the fixer in a subprocess with short_open_tag=On so that bare `<?`
     * short open tags are tokenized as T_OPEN_TAG, then returns the fixed file
     * contents. This is the only configuration under which the T_OPEN_TAG branch
     * of the fixer is exercised.
     */
    private function fixWithShortOpenTagEnabled(string $basePath): string
    {
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $runner   = $basePath . '/run-fix.php';

        file_put_contents($runner, <<<PHP
            <?php
            require {$this->exported($autoload)};

            use Boundwize\\StructArmed\\Architecture;
            use Boundwize\\StructArmed\\Rule\\Rules\\File\\Psr1PhpTagsRule;

            \$rule       = new Psr1PhpTagsRule(['src/']);
            \$violations = \$rule->evaluateProjectAll({$this->exported($basePath)}, Architecture::define());
            \$rule->fix(\$violations[0]);
            PHP);

        $command = escapeshellarg(PHP_BINARY)
            . ' -d short_open_tag=1 '
            . escapeshellarg($runner);
        exec($command, $output, $exitCode);

        $this->assertSame(0, $exitCode, 'Fixer runner failed: ' . implode("\n", $output));

        $fixedContent = (string) file_get_contents($basePath . '/src/Foo.php');
        unlink($runner);

        return $fixedContent;
    }

    private function exported(string $value): string
    {
        return var_export($value, true);
    }
}
