<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Composer;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4NamespaceRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function json_encode;
use function mkdir;

#[CoversClass(Psr4NamespaceRule::class)]
final class Psr4NamespaceRuleTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

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
        $this->assertSame('Class [Foo] must match PSR-4 class [App\\Tests\\Foo]', $violation->message);
    }

    public function testFailsWhenClassDoesNotMatchAbsoluteComposerPsr4Path(): void
    {
        $basePath = $this->makeTemporaryDirectory('structarmed-psr4-absolute');
        mkdir($basePath . '/src');

        file_put_contents($basePath . '/composer.json', json_encode([
            'autoload' => [
                'psr-4' => [
                    'App\\' => $basePath . '/src/',
                ],
            ],
        ]));

        $file = $basePath . '/src/Foo.php';
        file_put_contents($file, '<?php namespace Wrong; class Foo {}');

        $violation = (new Psr4NamespaceRule('Source'))
            ->evaluate($this->makeNode('Wrong\\Foo', $file));

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame('Class [Wrong\\Foo] must match PSR-4 class [App\\Foo]', $violation->message);
    }

    public function testFailsForPsr4PathOutsideProjectDirectory(): void
    {
        $rootPath = $this->makeTemporaryDirectory('structarmed-psr4-outside-project');
        mkdir($rootPath . '/project');
        mkdir($rootPath . '/shared/src', 0777, true);

        file_put_contents($rootPath . '/project/composer.json', json_encode([
            'autoload' => [
                'psr-4' => [
                    'App\\' => '../shared/src/',
                ],
            ],
        ]));

        $file = $rootPath . '/shared/src/Foo.php';
        file_put_contents($file, '<?php namespace Wrong; class Foo {}');

        $psr4NamespaceRule = new Psr4NamespaceRule('Source');

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $psr4NamespaceRule->evaluateProject($rootPath . '/project', Architecture::define())
        );

        $violation = $psr4NamespaceRule->evaluate($this->makeNode('Wrong\\Foo', $file));

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame('Class [Wrong\\Foo] must match PSR-4 class [App\\Foo]', $violation->message);

        mkdir($rootPath . '/shared/src/Sub');
        file_put_contents($rootPath . '/shared/src/Sub/Bar.php', '<?php namespace App\Sub; class Bar {}');

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $psr4NamespaceRule->evaluate($this->makeNode('App\\Sub\\Bar', $rootPath . '/shared/src/Sub/Bar.php'))
        );
    }

    #[DataProvider('nonClassKindProvider')]
    public function testViolationMessageNamesTheClassLikeKind(
        string $expectedKind,
        bool $isInterface,
        bool $isTrait,
        bool $isEnum,
    ): void {
        $basePath = $this->makeTempProject();
        $file     = $basePath . '/tests/Foo.php';

        file_put_contents($file, '<?php namespace App\Tests; interface Bar {}');

        $psr4NamespaceRule = new Psr4NamespaceRule('Source');

        $violation = $psr4NamespaceRule->evaluate($this->makeNode(
            'App\\Tests\\Bar',
            $file,
            isTrait: $isTrait,
            isInterface: $isInterface,
            isEnum: $isEnum,
        ));

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(
            $expectedKind . ' [App\\Tests\\Bar] must match PSR-4 class [App\\Tests\\Foo]',
            $violation->message
        );
    }

    /** @return iterable<string, array{string, bool, bool, bool}> */
    public static function nonClassKindProvider(): iterable
    {
        yield 'interface' => ['Interface', true, false, false];
        yield 'trait'     => ['Trait', false, true, false];
        yield 'enum'      => ['Enum', false, false, true];
    }

    public function testDoesNotApplyOutsideConfiguredLayer(): void
    {
        $psr4NamespaceRule = new Psr4NamespaceRule('Source');

        $this->assertFalse($psr4NamespaceRule->appliesTo($this->makeNode('Foo', '/fake.php', layer: 'Other')));
    }

    public function testPassesWhenComposerJsonCannotBeFound(): void
    {
        $basePath = $this->makeTemporaryDirectory('structarmed-psr4-namespace-missing');
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

    public function testFailsWhenTraitNameDoesNotMatchFilenameWithDuplicateNamespaceAcrossAutoloadSections(): void
    {
        $basePath = $this->makeTemporaryDirectory('structarmed-psr4-ci4');
        mkdir($basePath . '/system/Exceptions', 0777, true);
        mkdir($basePath . '/tests/system', 0777, true);

        file_put_contents($basePath . '/composer.json', json_encode([
            'autoload'     => ['psr-4' => ['CodeIgniter\\' => 'system/']],
            'autoload-dev' => ['psr-4' => ['CodeIgniter\\' => 'tests/system/']],
        ]));

        $file = $basePath . '/system/Exceptions/DebugTraceableTrait.php';
        file_put_contents($file, '<?php namespace CodeIgniter\Exceptions; trait DebugTraceableTraits {}');

        $psr4NamespaceRule = new Psr4NamespaceRule('Source');

        $violation = $psr4NamespaceRule->evaluate(
            $this->makeNode('CodeIgniter\\Exceptions\\DebugTraceableTraits', $file, isTrait: true)
        );

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('CodeIgniter\\Exceptions\\DebugTraceableTrait', $violation->message);
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

    public function testReusesCachedAncestorBasePathForNestedDirectory(): void
    {
        $basePath          = $this->makeTempProject();
        $psr4NamespaceRule = new Psr4NamespaceRule('Source');

        mkdir($basePath . '/tests/Sub', 0777, true);
        file_put_contents($basePath . '/tests/Foo.php', '<?php namespace App\Tests; class Foo {}');
        file_put_contents($basePath . '/tests/Sub/Bar.php', '<?php namespace App\Tests\Sub; class Bar {}');

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $psr4NamespaceRule->evaluate($this->makeNode('App\\Tests\\Foo', $basePath . '/tests/Foo.php'))
        );
        $this->assertNotInstanceOf(
            RuleViolation::class,
            $psr4NamespaceRule->evaluate($this->makeNode('App\\Tests\\Sub\\Bar', $basePath . '/tests/Sub/Bar.php'))
        );
    }

    public function testSelectsLongestPrefixMatchNotFirstDeclared(): void
    {
        $basePath = $this->makeTemporaryDirectory('structarmed-psr4-longest-prefix');
        mkdir($basePath . '/src/legacy', 0777, true);

        file_put_contents($basePath . '/composer.json', json_encode([
            'autoload' => [
                'psr-4' => [
                    'App\\'    => 'src/',
                    'Legacy\\' => 'src/legacy/',
                ],
            ],
        ]));

        $file = $basePath . '/src/legacy/Thing.php';
        file_put_contents($file, '<?php namespace Legacy; class Thing {}');

        $psr4NamespaceRule = new Psr4NamespaceRule('Source');

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $psr4NamespaceRule->evaluate($this->makeNode('Legacy\\Thing', $file))
        );
    }

    public function testSelectsLongestPrefixMatchNotLastDeclared(): void
    {
        $basePath = $this->makeTemporaryDirectory('structarmed-psr4-longest-prefix-flipped');
        mkdir($basePath . '/src/legacy', 0777, true);

        file_put_contents($basePath . '/composer.json', json_encode([
            'autoload' => [
                'psr-4' => [
                    'Legacy\\' => 'src/legacy/',
                    'App\\'    => 'src/',
                ],
            ],
        ]));

        $file = $basePath . '/src/legacy/Thing.php';
        file_put_contents($file, '<?php namespace Legacy; class Thing {}');

        $psr4NamespaceRule = new Psr4NamespaceRule('Source');

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $psr4NamespaceRule->evaluate($this->makeNode('Legacy\\Thing', $file))
        );
    }

    public function testAllowsShortPrefixNamespaceWhenFileAlsoMatchesLongerPrefix(): void
    {
        $basePath = $this->makeTemporaryDirectory('structarmed-psr4-overlapping-prefix');
        mkdir($basePath . '/src/Legacy', 0777, true);

        file_put_contents($basePath . '/composer.json', json_encode([
            'autoload' => [
                'psr-4' => [
                    'App\\'    => 'src/',
                    'Legacy\\' => 'src/Legacy/',
                ],
            ],
        ]));

        $file = $basePath . '/src/Legacy/Foo.php';
        file_put_contents($file, '<?php namespace App\Legacy; class Foo {}');

        $psr4NamespaceRule = new Psr4NamespaceRule('Source');

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $psr4NamespaceRule->evaluate($this->makeNode('App\\Legacy\\Foo', $file))
        );
    }

    public function testAllowsEitherNamespaceWhenTwoNamespacesMapToSameDirectory(): void
    {
        $basePath = $this->makeTemporaryDirectory('structarmed-psr4-same-length-prefix');
        mkdir($basePath . '/src/Bar', 0777, true);

        file_put_contents($basePath . '/composer.json', json_encode([
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                    'Foo\\' => 'src/',
                ],
            ],
        ]));

        $file = $basePath . '/src/Bar/Baz.php';
        file_put_contents($file, '<?php namespace App\Bar; class Baz {}');

        $psr4NamespaceRule = new Psr4NamespaceRule('Source');

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $psr4NamespaceRule->evaluate($this->makeNode('App\\Bar\\Baz', $file))
        );

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $psr4NamespaceRule->evaluate($this->makeNode('Foo\\Bar\\Baz', $file))
        );
    }

    private function makeNode(
        string $className,
        string $file,
        string $layer = 'Source',
        bool $isTrait = false,
        bool $isInterface = false,
        bool $isEnum = false,
    ): ClassNode {
        return new ClassNode(
            className:   $className,
            file:        $file,
            line:        1,
            layer:       $layer,
            extends:     null,
            isAbstract:  false,
            isFinal:     false,
            isInterface: $isInterface,
            isReadonly:  false,
            isTrait:     $isTrait,
            isEnum:      $isEnum,
        );
    }

    private function makeTempProject(): string
    {
        $basePath = $this->makeTemporaryDirectory('structarmed-psr4-namespace-rule');

        mkdir($basePath . '/tests', 0777, true);
        file_put_contents(
            $basePath . '/composer.json',
            '{"autoload-dev":{"psr-4":{"App\\\\Tests\\\\":"tests/"}}}'
        );

        return $basePath;
    }
}
