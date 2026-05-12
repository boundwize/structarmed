<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Composer;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4NamespaceRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;

#[CoversClass(Psr4NamespaceRule::class)]
final class Psr4NamespaceRuleTest extends TestCase
{
    public function testPassesWhenClassMatchesComposerPsr4Path(): void
    {
        $basePath = $this->makeTempProject();
        $file     = $basePath . '/tests/Foo.php';

        file_put_contents($file, '<?php namespace App\Tests; class Foo {}');

        $psr4NamespaceRule = new Psr4NamespaceRule('Source');

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $psr4NamespaceRule->evaluate($this->makeNode('App\\Tests\\Foo', $file))
        );
    }

    public function testFailsWhenClassDoesNotMatchComposerPsr4Path(): void
    {
        $basePath = $this->makeTempProject();
        $file     = $basePath . '/tests/Foo.php';

        file_put_contents($file, '<?php class Foo {}');

        $psr4NamespaceRule = new Psr4NamespaceRule('Source');

        $violation = $psr4NamespaceRule->evaluate($this->makeNode('Foo', $file));

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('App\\Tests\\Foo', $violation->message);
    }

    public function testDoesNotApplyOutsideConfiguredLayer(): void
    {
        $psr4NamespaceRule = new Psr4NamespaceRule('Source');

        $this->assertFalse($psr4NamespaceRule->appliesTo($this->makeNode('Foo', '/fake.php', layer: 'Other')));
    }

    public function testPassesWhenComposerJsonCannotBeFound(): void
    {
        $basePath = sys_get_temp_dir() . '/structarmed-psr4-namespace-missing-' . bin2hex(random_bytes(6));
        mkdir($basePath . '/src', 0777, true);

        $psr4NamespaceRule = new Psr4NamespaceRule('Source');

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $psr4NamespaceRule->evaluate($this->makeNode('Foo', $basePath . '/src/Foo.php'))
        );
    }

    public function testPassesWhenRelativeFileCannotFindComposerJson(): void
    {
        $psr4NamespaceRule = new Psr4NamespaceRule('Source');

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $psr4NamespaceRule->evaluate($this->makeNode('Foo', 'Foo.php'))
        );
    }

    public function testPassesWhenFileIsOutsideComposerPsr4Path(): void
    {
        $basePath = $this->makeTempProject();
        $file     = $basePath . '/other/Foo.php';

        mkdir($basePath . '/other');
        file_put_contents($file, '<?php class Foo {}');

        $psr4NamespaceRule = new Psr4NamespaceRule('Source');

        $this->assertNotInstanceOf(RuleViolation::class, $psr4NamespaceRule->evaluate($this->makeNode('Foo', $file)));
    }

    public function testPassesWhenRelativeFileIsNotPhpFile(): void
    {
        $basePath = $this->makeTempProject();
        $file     = $basePath . '/tests/Foo.inc';

        file_put_contents($file, '<?php class Foo {}');

        $psr4NamespaceRule = new Psr4NamespaceRule('Source');

        $this->assertNotInstanceOf(RuleViolation::class, $psr4NamespaceRule->evaluate($this->makeNode('Foo', $file)));
    }

    public function testStripsClassSuffixFromExpectedClassName(): void
    {
        $basePath = $this->makeTempProject();
        $file     = $basePath . '/tests/Foo.class.php';

        file_put_contents($file, '<?php namespace App\Tests; class Foo {}');

        $psr4NamespaceRule = new Psr4NamespaceRule('Source');

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $psr4NamespaceRule->evaluate($this->makeNode('App\\Tests\\Foo', $file))
        );
    }

    public function testFailsWhenTraitNameDoesNotMatchFilename(): void
    {
        $basePath = $this->makeTempProject();
        $file     = $basePath . '/tests/DebugTraceableTrait.php';

        file_put_contents($file, '<?php namespace App\Tests; trait DebugTraceableTraits {}');

        $psr4NamespaceRule = new Psr4NamespaceRule('Source');

        $violation = $psr4NamespaceRule->evaluate(
            $this->makeNode('App\\Tests\\DebugTraceableTraits', $file, isTrait: true)
        );

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('App\\Tests\\DebugTraceableTrait', $violation->message);
    }

    public function testCachesMappingsPerBasePath(): void
    {
        $basePath          = $this->makeTempProject();
        $psr4NamespaceRule = new Psr4NamespaceRule('Source');

        file_put_contents($basePath . '/tests/Foo.php', '<?php namespace App\Tests; class Foo {}');
        file_put_contents($basePath . '/tests/Bar.php', '<?php namespace App\Tests; class Bar {}');

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $psr4NamespaceRule->evaluate($this->makeNode('App\\Tests\\Foo', $basePath . '/tests/Foo.php'))
        );
        $this->assertNotInstanceOf(
            RuleViolation::class,
            $psr4NamespaceRule->evaluate($this->makeNode('App\\Tests\\Bar', $basePath . '/tests/Bar.php'))
        );
    }

    private function makeNode(string $className, string $file, string $layer = 'Source', bool $isTrait = false): ClassNode
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
            isTrait:     $isTrait,
        );
    }

    private function makeTempProject(): string
    {
        $basePath = sys_get_temp_dir() . '/structarmed-psr4-namespace-rule-' . bin2hex(random_bytes(6));

        mkdir($basePath . '/tests', 0777, true);
        file_put_contents(
            $basePath . '/composer.json',
            '{"autoload-dev":{"psr-4":{"App\\\\Tests\\\\":"tests/"}}}'
        );

        return $basePath;
    }
}
