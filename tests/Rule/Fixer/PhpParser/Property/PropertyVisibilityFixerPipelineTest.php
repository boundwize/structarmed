<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Fixer\PhpParser\Property;

use Boundwize\StructArmed\Rule\Fixer\PhpParser\PhpParserFixerProcessor;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\Property\AddPublicPropertyVisibilityVisitor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(PhpParserFixerProcessor::class)]
#[CoversClass(AddPublicPropertyVisibilityVisitor::class)]
final class PropertyVisibilityFixerPipelineTest extends TestCase
{
    public function testProcessAddsPublicVisibilityToClassicProperty(): void
    {
        $file = $this->temporaryPhpFile(<<<'PHP'
<?php

namespace App;

class Order
{
    static $status;
}
PHP);

        try {
            $this->assertTrue($this->process($file, 'App\\Order', 'status'));
            $this->assertStringContainsString(
                '    public static $status;',
                (string) file_get_contents($file)
            );
        } finally {
            unlink($file);
        }
    }

    public function testProcessAddsPublicVisibilityToPromotedReadonlyProperty(): void
    {
        $file = $this->temporaryPhpFile(<<<'PHP'
<?php

namespace App;

class Order
{
    public function __construct(readonly string $status)
    {
    }
}
PHP);

        try {
            $this->assertTrue($this->process($file, 'App\\Order', 'status'));
            $this->assertStringContainsString(
                'public function __construct(public readonly string $status)',
                (string) file_get_contents($file)
            );
        } finally {
            unlink($file);
        }
    }

    public function testProcessReturnsFalseWhenPromotedPropertyAlreadyHasVisibility(): void
    {
        $file = $this->temporaryPhpFile(<<<'PHP'
<?php

namespace App;

class Order
{
    public function __construct(private readonly string $status)
    {
    }
}
PHP);

        try {
            $this->assertFalse($this->process($file, 'App\\Order', 'status'));
        } finally {
            unlink($file);
        }
    }

    public function testProcessReturnsFalseForRegularConstructorParameter(): void
    {
        $file = $this->temporaryPhpFile(<<<'PHP'
<?php

namespace App;

class Order
{
    public function __construct(string $status)
    {
    }
}
PHP);

        try {
            $this->assertFalse($this->process($file, 'App\\Order', 'status'));
        } finally {
            unlink($file);
        }
    }

    private function process(string $file, string $className, string $propertyName): bool
    {
        return (new PhpParserFixerProcessor())->process(
            $file,
            new AddPublicPropertyVisibilityVisitor($className, $propertyName),
        );
    }

    private function temporaryPhpFile(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'structarmed-property-fixer-');
        $this->assertIsString($file);

        file_put_contents($file, $contents);

        return $file;
    }
}
