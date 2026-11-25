<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Fixer\PhpParser;

use Boundwize\StructArmed\Rule\Fixer\PhpParser\EnumCase\MoveEnumCaseToOwnLineVisitor;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\FunctionLike\AddStaticAnonymousFunctionVisitor;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\PhpParserFixerProcessor;
use PhpParser\Node;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(PhpParserFixerProcessor::class)]
final class PhpParserFixerProcessorTest extends TestCase
{
    private const CODE = <<<'PHP'
<?php

namespace App;

enum Status { case Active; case Inactive; }

function unused(): void
{
}

function callback(): \Closure { return fn () => 42; }

PHP;

    public function testProcessReportsNoLineCountChangeWhenFixKeepsTheLines(): void
    {
        $file                                             = $this->temporaryPhpFile(self::CODE);
        PhpParserFixerProcessor::$lastFixChangedLineCount = true;

        try {
            $this->assertTrue((new PhpParserFixerProcessor())->process(
                $file,
                new AddStaticAnonymousFunctionVisitor(11),
            ));
            $this->assertFalse(PhpParserFixerProcessor::$lastFixChangedLineCount);
            $this->assertStringContainsString('return static fn () => 42;', (string) file_get_contents($file));
        } finally {
            unlink($file);
        }
    }

    public function testProcessReportsLineCountChangeWhenFixAddsOrRemovesLines(): void
    {
        $file                    = $this->temporaryPhpFile(self::CODE);
        $phpParserFixerProcessor = new PhpParserFixerProcessor();

        try {
            PhpParserFixerProcessor::$lastFixChangedLineCount = false;

            $this->assertTrue($phpParserFixerProcessor->process(
                $file,
                new MoveEnumCaseToOwnLineVisitor('App\Status', 'Inactive'),
            ));
            $this->assertTrue(PhpParserFixerProcessor::$lastFixChangedLineCount);
            PhpParserFixerProcessor::$lastFixChangedLineCount = false;

            $this->assertTrue($phpParserFixerProcessor->process(
                $file,
                $this->removeFunctionVisitor('unused'),
            ));
            $this->assertTrue(PhpParserFixerProcessor::$lastFixChangedLineCount);
            $this->assertStringNotContainsString('unused', (string) file_get_contents($file));
        } finally {
            unlink($file);
        }
    }

    public function testProcessDoesNotLeakLineCountChangeIntoLaterCalls(): void
    {
        $file                                             = $this->temporaryPhpFile(self::CODE);
        $otherFile                                        = $this->temporaryPhpFile(self::CODE);
        $phpParserFixerProcessor                          = new PhpParserFixerProcessor();
        PhpParserFixerProcessor::$lastFixChangedLineCount = false;

        try {
            $this->assertTrue($phpParserFixerProcessor->process(
                $file,
                $this->removeFunctionVisitor('unused'),
            ));
            $this->assertTrue(PhpParserFixerProcessor::$lastFixChangedLineCount);

            // The flag is shared by the next calls: a fix of another
            // file that keeps its lines, then fixes that change nothing.
            $this->assertTrue($phpParserFixerProcessor->process(
                $otherFile,
                new AddStaticAnonymousFunctionVisitor(11),
            ));
            $this->assertFalse(PhpParserFixerProcessor::$lastFixChangedLineCount);
            PhpParserFixerProcessor::$lastFixChangedLineCount = true;

            $this->assertFalse($phpParserFixerProcessor->process(
                $file,
                $this->removeFunctionVisitor('unused'),
            ));
            $this->assertFalse(PhpParserFixerProcessor::$lastFixChangedLineCount);
            PhpParserFixerProcessor::$lastFixChangedLineCount = true;

            $this->assertFalse($phpParserFixerProcessor->process(
                $file . '-missing',
                $this->removeFunctionVisitor('unused'),
            ));
            $this->assertFalse(PhpParserFixerProcessor::$lastFixChangedLineCount);
        } finally {
            unlink($file);
            unlink($otherFile);
        }
    }

    public function testProcessReportsLineCountChangeWhenFixDeletesTheFile(): void
    {
        $file                                             = $this->temporaryPhpFile(<<<'PHP'
<?php

namespace App;

function unused(): void
{
}

PHP);
        PhpParserFixerProcessor::$lastFixChangedLineCount = false;

        $this->assertTrue((new PhpParserFixerProcessor())->process(
            $file,
            $this->removeFunctionVisitor('unused'),
            true,
        ));
        $this->assertTrue(PhpParserFixerProcessor::$lastFixChangedLineCount);
        $this->assertFileDoesNotExist($file);
    }

    private function removeFunctionVisitor(string $functionName): NodeVisitor
    {
        return new class ($functionName) extends NodeVisitorAbstract {
            public function __construct(private readonly string $functionName)
            {
            }

            public function leaveNode(Node $node): ?int
            {
                return $node instanceof Function_ && $node->name->toString() === $this->functionName
                    ? NodeVisitor::REMOVE_NODE
                    : null;
            }
        };
    }

    private function temporaryPhpFile(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'structarmed-php-fixer-');
        $this->assertIsString($file);

        file_put_contents($file, $contents);

        return $file;
    }
}
