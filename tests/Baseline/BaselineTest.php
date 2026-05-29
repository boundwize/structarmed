<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Baseline;

use App\Foo;
use Boundwize\StructArmed\Baseline\Baseline;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Rule\RuleViolationCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function bin2hex;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function restore_error_handler;
use function rmdir;
use function set_error_handler;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(Baseline::class)]
final class BaselineTest extends TestCase
{
    public function testGenerateRejectsEmptyBaselinePath(): void
    {
        $basePath = $this->createTempDirectory();

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Baseline path cannot be empty.');

            (new Baseline())->generate(new RuleViolationCollection(), '', $basePath);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testGenerateRejectsMissingBaselineDirectory(): void
    {
        $basePath = $this->createTempDirectory();

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Baseline directory [' . $basePath . '/missing] does not exist.');

            (new Baseline())->generate(new RuleViolationCollection(), 'missing/baseline.php', $basePath);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testGenerateReportsUnwritableBaselineFile(): void
    {
        $basePath = $this->createTempDirectory();
        mkdir($basePath . '/baseline.php');

        set_error_handler(static fn(): bool => true);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Could not write baseline file [baseline.php].');

            (new Baseline())->generate(new RuleViolationCollection(), 'baseline.php', $basePath);
        } finally {
            restore_error_handler();
            $this->removeTempDirectory($basePath, ['baseline.php']);
        }
    }

    public function testGenerateUsesAbsoluteBaselinePath(): void
    {
        $basePath                = $this->createTempDirectory();
        $baselinePath            = $basePath . '/baseline.php';
        $ruleViolationCollection = new RuleViolationCollection();
        $ruleViolationCollection->add($this->violation($basePath . '/src/Foo.php'));

        try {
            (new Baseline())->generate($ruleViolationCollection, $baselinePath, $basePath);

            $this->assertFileExists($baselinePath);
        } finally {
            $this->removeTempDirectory($basePath, ['baseline.php']);
        }
    }

    public function testGenerateWritesPrettyPrintedShortArraySyntax(): void
    {
        $basePath                = $this->createTempDirectory();
        $ruleViolationCollection = new RuleViolationCollection();

        mkdir($basePath . '/src');
        file_put_contents($basePath . '/src/Foo.php', '<?php');

        $ruleViolationCollection->add($this->violation($basePath . '/src/Foo.php'));

        try {
            (new Baseline())->generate($ruleViolationCollection, 'baseline.php', $basePath);

            $this->assertSame(<<<'PHP'
<?php

declare(strict_types=1);

return [
    [
        'rule' => 'source.must_be_final',
        'message' => 'Foo must be final',
        'file' => 'src/Foo.php',
        'class' => 'App\Foo',
        'layer' => 'Source',
    ],
];

PHP, file_get_contents($basePath . '/baseline.php'));
        } finally {
            $this->removeTempDirectory($basePath, ['baseline.php', 'src/Foo.php', 'src']);
        }
    }

    public function testGenerateNormalisesBasePathViolationToEmptyFile(): void
    {
        $basePath                = $this->createTempDirectory();
        $ruleViolationCollection = new RuleViolationCollection();
        $ruleViolationCollection->add($this->violation($basePath));

        try {
            (new Baseline())->generate($ruleViolationCollection, 'baseline.php', $basePath);

            $this->assertStringContainsString("'file' => ''", (string) file_get_contents($basePath . '/baseline.php'));
        } finally {
            $this->removeTempDirectory($basePath, ['baseline.php']);
        }
    }

    public function testFilterKeepsViolationsMissingFromBaseline(): void
    {
        $basePath                = $this->createTempDirectory();
        $baseline                = $basePath . '/baseline.php';
        $ruleViolationCollection = new RuleViolationCollection();

        mkdir($basePath . '/src');
        file_put_contents($basePath . '/src/Foo.php', '<?php');
        file_put_contents($basePath . '/src/Bar.php', '<?php');

        $ruleViolationCollection->add($this->violation($basePath . '/src/Foo.php'));
        $ruleViolationCollection->add($this->violation($basePath . '/src/Bar.php', 'Bar must be final', 'App\Bar'));

        try {
            file_put_contents($baseline, <<<'PHP'
<?php

declare(strict_types=1);

return [
    'not an array',
    [
        'rule' => 'source.must_be_final',
        'message' => 'Foo must be final',
        'file' => 'src/Foo.php',
        'class' => 'App\Foo',
        'layer' => 'Source',
    ],
];
PHP);

            $filtered = (new Baseline())->filter($ruleViolationCollection, 'baseline.php', $basePath);

            $this->assertCount(1, $filtered);

            foreach ($filtered as $violation) {
                $this->assertSame('Bar must be final', $violation->message);
            }
        } finally {
            $this->removeTempDirectory($basePath, ['baseline.php', 'src/Foo.php', 'src/Bar.php', 'src']);
        }
    }

    public function testFilterStillMatchesAfterNewLineAddedToSourceFile(): void
    {
        $basePath                = $this->createTempDirectory();
        $baseline                = $basePath . '/baseline.php';
        $ruleViolationCollection = new RuleViolationCollection();

        mkdir($basePath . '/src');
        file_put_contents($basePath . '/src/Foo.php', '<?php');

        $ruleViolationCollection->add($this->violation($basePath . '/src/Foo.php'));

        file_put_contents($baseline, <<<'PHP'
<?php

declare(strict_types=1);

return [
    [
        'rule' => 'source.must_be_final',
        'message' => 'Foo must be final',
        'file' => 'src/Foo.php',
        'class' => 'App\Foo',
        'layer' => 'Source',
    ],
];
PHP);

        try {
            // Add a new line at the top of the source file, shifting the violation to line 2
            file_put_contents($basePath . '/src/Foo.php', "\n<?php");

            $collectionAfterNewLine = new RuleViolationCollection();
            $collectionAfterNewLine->add(
                new RuleViolation(
                    'Foo must be final',
                    $basePath . '/src/Foo.php',
                    2,
                    Foo::class,
                    'Source',
                    'source.must_be_final'
                )
            );

            $filtered = (new Baseline())->filter($collectionAfterNewLine, 'baseline.php', $basePath);

            $this->assertCount(0, $filtered);
        } finally {
            $this->removeTempDirectory($basePath, ['baseline.php', 'src/Foo.php', 'src']);
        }
    }

    public function testFilterRejectsMissingBaselineFile(): void
    {
        $basePath = $this->createTempDirectory();

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Baseline file [baseline.php] does not exist.');

            (new Baseline())->filter(new RuleViolationCollection(), 'baseline.php', $basePath);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testFilterRejectsBaselineThatDoesNotReturnArray(): void
    {
        $basePath = $this->createTempDirectory();

        try {
            file_put_contents($basePath . '/baseline.php', <<<'PHP'
<?php

declare(strict_types=1);

return 'invalid';
PHP);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Baseline file [baseline.php] must return an array.');

            (new Baseline())->filter(new RuleViolationCollection(), 'baseline.php', $basePath);
        } finally {
            $this->removeTempDirectory($basePath, ['baseline.php']);
        }
    }

    public function testFilterNormalisesMalformedBaselineValuesAndBasePathViolation(): void
    {
        $basePath                = $this->createTempDirectory();
        $baseline                = $basePath . '/baseline.php';
        $ruleViolationCollection = new RuleViolationCollection();
        $ruleViolationCollection->add(new RuleViolation('', '', 0, '', null, ''));

        try {
            file_put_contents($baseline, <<<'PHP'
<?php

declare(strict_types=1);

return [
    [
        'rule' => [],
        'message' => [],
        'file' => [],
        'class' => [],
    ],
];
PHP);

            $filtered = (new Baseline())->filter($ruleViolationCollection, 'baseline.php', $basePath);

            $this->assertCount(0, $filtered);
        } finally {
            $this->removeTempDirectory($basePath, ['baseline.php']);
        }
    }

    public function testFilterDistinguishesInvalidUtf8Signatures(): void
    {
        $basePath                = $this->createTempDirectory();
        $baseline                = $basePath . '/baseline.php';
        $ruleViolationCollection = new RuleViolationCollection();
        $ruleViolationCollection->add($this->violation($basePath . '/src/Foo.php', "Foo \xB1", Foo::class));
        $ruleViolationCollection->add($this->violation($basePath . '/src/Bar.php', "Bar \xB1", 'App\Bar'));

        mkdir($basePath . '/src');
        file_put_contents($basePath . '/src/Foo.php', '<?php');
        file_put_contents($basePath . '/src/Bar.php', '<?php');

        try {
            file_put_contents($baseline, <<<'PHP'
<?php

declare(strict_types=1);

return [
    [
        'rule' => 'source.must_be_final',
        'message' => "Foo \xB1",
        'file' => 'src/Foo.php',
        'class' => 'App\Foo',
        'layer' => 'Source',
    ],
];
PHP);

            $filtered = (new Baseline())->filter($ruleViolationCollection, 'baseline.php', $basePath);

            $this->assertCount(1, $filtered);

            foreach ($filtered as $violation) {
                $this->assertSame("Bar \xB1", $violation->message);
            }
        } finally {
            $this->removeTempDirectory($basePath, ['baseline.php', 'src/Foo.php', 'src/Bar.php', 'src']);
        }
    }

    private function createTempDirectory(): string
    {
        $basePath = sys_get_temp_dir() . '/structarmed-baseline-' . bin2hex(random_bytes(6));
        mkdir($basePath);

        return $basePath;
    }

    /**
     * @param list<string> $paths
     */
    private function removeTempDirectory(string $basePath, array $paths = []): void
    {
        foreach ($paths as $path) {
            $absolutePath = $basePath . '/' . $path;

            if (is_dir($absolutePath)) {
                rmdir($absolutePath);

                continue;
            }

            if (file_exists($absolutePath)) {
                unlink($absolutePath);
            }
        }

        rmdir($basePath);
    }

    private function violation(
        string $file,
        string $message = 'Foo must be final',
        string $className = Foo::class
    ): RuleViolation {
        return new RuleViolation($message, $file, 1, $className, 'Source', 'source.must_be_final');
    }
}
