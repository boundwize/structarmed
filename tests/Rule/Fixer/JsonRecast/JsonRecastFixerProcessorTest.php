<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Fixer\JsonRecast;

use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitorAbstract;
use Boundwize\StructArmed\Rule\Fixer\JsonRecast\JsonRecastFixerProcessor;
use Boundwize\StructArmed\Rule\Fixer\JsonRecast\ObjectItemNode\RemoveMissingPsr4PathVisitor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(JsonRecastFixerProcessor::class)]
final class JsonRecastFixerProcessorTest extends TestCase
{
    public function testProcessHandlesUnavailableFileAndInvalidTraversalRoot(): void
    {
        $file = $this->temporaryJsonFile('{}');
        unlink($file);

        $this->assertFalse($this->process($file));

        $file = $this->temporaryJsonFile('{}');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('JsonRecast fixer traversal must return JsonDocument.');

            (new JsonRecastFixerProcessor())->process(
                $file,
                new class extends NodeJsonVisitorAbstract {
                    public function beforeTraverse(NodeJson $nodeJson): StringNode
                    {
                        return new StringNode('replacement');
                    }
                },
            );
        } finally {
            unlink($file);
        }
    }

    public function testProcessReturnsFalseWhenJsonCannotBeParsed(): void
    {
        $file = $this->temporaryJsonFile('{not json');

        try {
            $this->assertFalse($this->process($file));
        } finally {
            unlink($file);
        }
    }

    public function testProcessReportsLineCountChangeOnlyForTheCallThatChangedIt(): void
    {
        $multiLineFile  = $this->temporaryJsonFile(<<<'JSON'
{
    "autoload": {
        "psr-4": {
            "Existing\\": "./",
            "Missing\\": "missing/"
        }
    }
}
JSON);
        $singleLineFile = $this->temporaryJsonFile(
            '{"autoload": {"psr-4": {"Existing\\\\": "./", "Missing\\\\": "missing/"}}}'
        );

        $jsonRecastFixerProcessor                          = new JsonRecastFixerProcessor();
        $removeMissingPsr4PathVisitor                      = new RemoveMissingPsr4PathVisitor(dirname($multiLineFile));
        JsonRecastFixerProcessor::$lastFixChangedLineCount = false;

        try {
            $this->assertTrue(
                $jsonRecastFixerProcessor->process($multiLineFile, $removeMissingPsr4PathVisitor)
            );
            $this->assertTrue(JsonRecastFixerProcessor::$lastFixChangedLineCount);
            $this->assertStringNotContainsString('Missing', (string) file_get_contents($multiLineFile));

            // The flag is shared by the next calls: a fix that keeps
            // the lines of another file, then one that changes nothing.
            $this->assertTrue(
                $jsonRecastFixerProcessor->process($singleLineFile, $removeMissingPsr4PathVisitor)
            );
            $this->assertFalse(JsonRecastFixerProcessor::$lastFixChangedLineCount);
            $this->assertStringNotContainsString('Missing', (string) file_get_contents($singleLineFile));
            JsonRecastFixerProcessor::$lastFixChangedLineCount = true;

            $this->assertFalse(
                $jsonRecastFixerProcessor->process($multiLineFile, $removeMissingPsr4PathVisitor)
            );
            $this->assertFalse(JsonRecastFixerProcessor::$lastFixChangedLineCount);
        } finally {
            unlink($multiLineFile);
            unlink($singleLineFile);
        }
    }

    private function process(string $file): bool
    {
        return (new JsonRecastFixerProcessor())->process(
            $file,
            new class extends NodeJsonVisitorAbstract {
            },
        );
    }

    private function temporaryJsonFile(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'structarmed-jsonrecast-fixer-');
        $this->assertIsString($file);

        file_put_contents($file, $contents);

        return $file;
    }
}
