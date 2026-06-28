<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Fixer\JsonRecast;

use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitorAbstract;
use Boundwize\StructArmed\Rule\Fixer\JsonRecast\JsonRecastFixerProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(JsonRecastFixerProcessor::class)]
final class JsonRecastFixerProcessorTest extends TestCase
{
    public function testProcessReturnsFalseWhenFileDoesNotExist(): void
    {
        $file = $this->temporaryJsonFile('{}');
        unlink($file);

        $this->assertFalse($this->process($file));
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
