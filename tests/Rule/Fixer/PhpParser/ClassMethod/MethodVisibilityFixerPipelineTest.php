<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Fixer\PhpParser\ClassMethod;

use Boundwize\StructArmed\Rule\Fixer\PhpParser\ClassMethod\AddPublicMethodVisibilityVisitor;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\PhpParserFixerProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(PhpParserFixerProcessor::class)]
#[CoversClass(AddPublicMethodVisibilityVisitor::class)]
final class MethodVisibilityFixerPipelineTest extends TestCase
{
    public function testProcessAddsPublicVisibilityToNamespacedMethod(): void
    {
        $file = $this->temporaryPhpFile(<<<'PHP'
<?php

namespace App;

class Order
{
    static function save(): void
    {
    }
}
PHP);

        try {
            $this->assertTrue($this->process($file, 'App\\Order', 'save'));
            $this->assertStringContainsString(
                '    public static function save(): void',
                (string) file_get_contents($file)
            );
        } finally {
            unlink($file);
        }
    }

    public function testProcessReturnsFalseForMissingFile(): void
    {
        $this->assertFalse($this->process(sys_get_temp_dir() . '/missing-structarmed.php', 'App\\Order', 'save'));
    }

    public function testProcessReturnsFalseForInvalidPhp(): void
    {
        $file = $this->temporaryPhpFile('<?php class Broken {');

        try {
            $this->assertFalse($this->process($file, 'App\\Order', 'save'));
        } finally {
            unlink($file);
        }
    }

    public function testProcessReturnsFalseForEmptyPhpFile(): void
    {
        $file = $this->temporaryPhpFile("<?php\n");

        try {
            $this->assertFalse($this->process($file, 'App\\Order', 'save'));
        } finally {
            unlink($file);
        }
    }

    public function testProcessReturnsFalseWhenMethodAlreadyHasVisibility(): void
    {
        $file = $this->temporaryPhpFile(<<<'PHP'
<?php

namespace App;

class Order
{
    public function save(): void
    {
    }
}
PHP);

        try {
            $this->assertFalse($this->process($file, 'App\\Order', 'save'));
        } finally {
            unlink($file);
        }
    }

    public function testProcessReturnsFalseWhenNoMethodMatches(): void
    {
        $file = $this->temporaryPhpFile(<<<'PHP'
<?php

namespace App;

class Order
{
    public function make(): object
    {
        return new class {
            function run(): void
            {
            }
        };
    }
}
PHP);

        try {
            $this->assertFalse($this->process($file, 'App\\Missing', 'save'));
            $this->assertFalse($this->process($file, 'App\\Order', 'missing'));
        } finally {
            unlink($file);
        }
    }

    private function process(string $file, string $className, string $methodName): bool
    {
        return (new PhpParserFixerProcessor())->process(
            $file,
            new AddPublicMethodVisibilityVisitor($className, $methodName),
        );
    }

    private function temporaryPhpFile(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'structarmed-method-fixer-');
        $this->assertIsString($file);

        file_put_contents($file, $contents);

        return $file;
    }
}
