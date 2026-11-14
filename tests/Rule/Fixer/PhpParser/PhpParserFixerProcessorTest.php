<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Fixer\PhpParser;

use ArrayObject;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\Function_\RemoveFunctionVisitor;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\PhpParserFixerProcessor;
use PhpParser\Node;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeVisitorAbstract;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(PhpParserFixerProcessor::class)]
#[CoversClass(RemoveFunctionVisitor::class)]
final class PhpParserFixerProcessorTest extends TestCase
{
    public function testProcessRunsAllVisitorsInOneTraversal(): void
    {
        $file = $this->temporaryPhpFile(<<<'PHP'
<?php

namespace App;

function first()
{
}

function second()
{
}
PHP);

        $events   = new ArrayObject();
        $visitors = [
            $this->recordingVisitor('a', $events),
            $this->recordingVisitor('b', $events),
        ];

        try {
            $this->assertFalse((new PhpParserFixerProcessor())->process($file, $visitors));
        } finally {
            unlink($file);
        }

        // A traversal per visitor would record both of "a" before any of "b".
        $this->assertSame(
            ['a:before', 'b:before', 'a:first', 'b:first', 'a:second', 'b:second'],
            $events->getArrayCopy()
        );
    }

    public function testProcessRemovesGuardEmptiedByVisitorsOfOneTraversal(): void
    {
        $file = $this->temporaryPhpFile(<<<'PHP'
<?php

namespace App;

if (! function_exists('App\first')) {
    function first()
    {
    }

    function second()
    {
    }
}

function kept()
{
}
PHP);

        try {
            $this->assertTrue((new PhpParserFixerProcessor())->process($file, [
                new RemoveFunctionVisitor('App\\first'),
                new RemoveFunctionVisitor('App\\second'),
            ]));
            $this->assertSame(
                <<<'PHP'
<?php

namespace App;

function kept()
{
}
PHP,
                (string) file_get_contents($file)
            );
        } finally {
            unlink($file);
        }
    }

    /** @param ArrayObject<int, string> $events */
    private function recordingVisitor(string $name, ArrayObject $events): NodeVisitorAbstract
    {
        return new class ($name, $events) extends NodeVisitorAbstract {
            /** @param ArrayObject<int, string> $events */
            public function __construct(
                private readonly string $name,
                private readonly ArrayObject $events,
            ) {
            }

            /** @param Node[] $nodes */
            public function beforeTraverse(array $nodes): null
            {
                $this->events[] = $this->name . ':before';

                return null;
            }

            public function enterNode(Node $node): null
            {
                if ($node instanceof Function_) {
                    $this->events[] = $this->name . ':' . $node->name->toString();
                }

                return null;
            }
        };
    }

    private function temporaryPhpFile(string $code): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'structarmed-fixer-');
        file_put_contents($file, $code);

        return $file;
    }
}
