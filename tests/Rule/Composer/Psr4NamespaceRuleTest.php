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

    private function makeNode(string $className, string $file): ClassNode
    {
        return new ClassNode(
            className:   $className,
            file:        $file,
            line:        1,
            layer:       'Source',
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
