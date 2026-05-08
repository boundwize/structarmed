<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Composer;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4NamespaceRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;

#[CoversClass(Psr4NamespaceRule::class)]
final class Psr4NamespaceRuleTest extends TestCase
{
    public function testPassesWhenClassMatchesComposerPsr4Path(): void
    {
        $basePath = $this->makeTempProject();
        $file     = $basePath . '/tests/Foo.php';

        file_put_contents($file, '<?php namespace App\Tests; class Foo {}');

        $rule = new Psr4NamespaceRule('Source');

        $this->assertNull($rule->evaluate($this->makeNode('App\\Tests\\Foo', $file)));
    }

    public function testFailsWhenClassDoesNotMatchComposerPsr4Path(): void
    {
        $basePath = $this->makeTempProject();
        $file     = $basePath . '/tests/Foo.php';

        file_put_contents($file, '<?php class Foo {}');

        $rule = new Psr4NamespaceRule('Source');

        $violation = $rule->evaluate($this->makeNode('Foo', $file));

        $this->assertNotNull($violation);
        $this->assertStringContainsString('App\\Tests\\Foo', $violation->message);
    }

    public function testDoesNotApplyOutsideConfiguredLayer(): void
    {
        $rule = new Psr4NamespaceRule('Source');

        $this->assertFalse($rule->appliesTo($this->makeNode('Foo', '/fake.php', layer: 'Other')));
    }

    public function testPassesWhenComposerJsonCannotBeFound(): void
    {
        $basePath = '/private/tmp/structarmed-psr4-namespace-missing-' . bin2hex(random_bytes(6));
        mkdir($basePath . '/src', 0777, true);

        $rule = new Psr4NamespaceRule('Source');

        $this->assertNull($rule->evaluate($this->makeNode('Foo', $basePath . '/src/Foo.php')));
    }

    public function testPassesWhenRelativeFileCannotFindComposerJson(): void
    {
        $rule = new Psr4NamespaceRule('Source');

        $this->assertNull($rule->evaluate($this->makeNode('Foo', 'Foo.php')));
    }

    public function testPassesWhenFileIsOutsideComposerPsr4Path(): void
    {
        $basePath = $this->makeTempProject();
        $file     = $basePath . '/other/Foo.php';

        mkdir($basePath . '/other');
        file_put_contents($file, '<?php class Foo {}');

        $rule = new Psr4NamespaceRule('Source');

        $this->assertNull($rule->evaluate($this->makeNode('Foo', $file)));
    }

    public function testPassesWhenRelativeFileIsNotPhpFile(): void
    {
        $basePath = $this->makeTempProject();
        $file     = $basePath . '/tests/Foo.inc';

        file_put_contents($file, '<?php class Foo {}');

        $rule = new Psr4NamespaceRule('Source');

        $this->assertNull($rule->evaluate($this->makeNode('Foo', $file)));
    }

    public function testStripsClassSuffixFromExpectedClassName(): void
    {
        $basePath = $this->makeTempProject();
        $file     = $basePath . '/tests/Foo.class.php';

        file_put_contents($file, '<?php namespace App\Tests; class Foo {}');

        $rule = new Psr4NamespaceRule('Source');

        $this->assertNull($rule->evaluate($this->makeNode('App\\Tests\\Foo', $file)));
    }

    public function testCachesMappingsPerBasePath(): void
    {
        $basePath = $this->makeTempProject();
        $rule     = new Psr4NamespaceRule('Source');

        file_put_contents($basePath . '/tests/Foo.php', '<?php namespace App\Tests; class Foo {}');
        file_put_contents($basePath . '/tests/Bar.php', '<?php namespace App\Tests; class Bar {}');

        $this->assertNull($rule->evaluate($this->makeNode('App\\Tests\\Foo', $basePath . '/tests/Foo.php')));
        $this->assertNull($rule->evaluate($this->makeNode('App\\Tests\\Bar', $basePath . '/tests/Bar.php')));
    }

    private function makeNode(string $className, string $file, string $layer = 'Source'): ClassNode
    {
        return new ClassNode(
            className:   $className,
            file:        $file,
            line:        1,
            layer:       $layer,
            extends:     null,
            isAbstract:  false,
            isFinal:     false,
            isInterface: false,
            isReadonly:  false,
        );
    }

    private function makeTempProject(): string
    {
        $basePath = '/private/tmp/structarmed-psr4-namespace-rule-' . bin2hex(random_bytes(6));

        mkdir($basePath . '/tests', 0777, true);
        file_put_contents(
            $basePath . '/composer.json',
            '{"autoload-dev":{"psr-4":{"App\\\\Tests\\\\":"tests/"}}}'
        );

        return $basePath;
    }
}
