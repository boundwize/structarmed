<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Fixer;

use Boundwize\StructArmed\Rule\Fixer\AddPublicMethodVisibilityVisitor;
use Boundwize\StructArmed\Rule\Fixer\ClassMethodVisibilityFixer;
use Boundwize\StructArmed\Rule\Fixer\PhpParserFixerProcessor;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(ClassMethodVisibilityFixer::class)]
#[CoversClass(PhpParserFixerProcessor::class)]
#[CoversClass(AddPublicMethodVisibilityVisitor::class)]
final class ClassMethodVisibilityFixerTest extends TestCase
{
    public function testFixAddsPublicVisibilityToNamespacedMethod(): void
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
            $this->assertTrue($this->fixer()->fix($this->violation($file, 7, 'App\\Order')));
            $this->assertStringContainsString(
                '    public static function save(): void',
                (string) file_get_contents($file)
            );
        } finally {
            unlink($file);
        }
    }

    public function testFixReturnsFalseForMissingFile(): void
    {
        $this->assertFalse($this->fixer()->fix($this->violation(sys_get_temp_dir() . '/missing-structarmed.php')));
    }

    public function testFixReturnsFalseForInvalidPhp(): void
    {
        $file = $this->temporaryPhpFile('<?php class Broken {');

        try {
            $this->assertFalse($this->fixer()->fix($this->violation($file)));
        } finally {
            unlink($file);
        }
    }

    public function testFixReturnsFalseWhenMethodAlreadyHasVisibility(): void
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
            $this->assertFalse($this->fixer()->fix($this->violation($file, 7, 'App\\Order')));
        } finally {
            unlink($file);
        }
    }

    public function testFixReturnsFalseWhenNoMethodMatches(): void
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
            $this->assertFalse($this->fixer()->fix($this->violation($file, 10, 'App\\Missing')));
        } finally {
            unlink($file);
        }
    }

    public function testFixReturnsFalseWhenViolationLineIsInvalid(): void
    {
        $this->assertFalse((new ClassMethodVisibilityFixer())->fix(new RuleViolation(
            message:   'Method must declare visibility',
            file:      '/missing.php',
            line:      0,
            className: 'App\\Order',
        )));
    }

    public function testFixReturnsFalseWhenViolationHasNoMethodName(): void
    {
        $this->assertFalse((new ClassMethodVisibilityFixer())->fix(new RuleViolation(
            message:   'Method must declare visibility',
            file:      '/missing.php',
            line:      1,
            className: 'App\\Order',
        )));
    }

    private function fixer(): ClassMethodVisibilityFixer
    {
        return new ClassMethodVisibilityFixer();
    }

    private function violation(
        string $file,
        int $line = 1,
        string $className = 'App\\Order',
        string $methodName = 'save',
    ): RuleViolation {
        return new RuleViolation(
            message:    'Method must declare visibility',
            file:       $file,
            line:       $line,
            className:  $className,
            methodName: $methodName,
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
