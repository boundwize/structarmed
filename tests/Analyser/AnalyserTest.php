<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser;

use Boundwize\StructArmed\Analyser\Analyser;
use Boundwize\StructArmed\Analyser\AnalyserOptions;
use Boundwize\StructArmed\Analyser\AnonymousFunctionNode;
use Boundwize\StructArmed\Analyser\FileAnalysisProvider;
use Boundwize\StructArmed\Analyser\FunctionNode;
use Boundwize\StructArmed\Analyser\Parallel\ParallelAnalysisNodeExtractor;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Cache\AnalysisResultCache;
use Boundwize\StructArmed\Cache\FileHashProvider;
use Boundwize\StructArmed\File\PhpFileCollector;
use Boundwize\StructArmed\File\SkipPathMatcher;
use Boundwize\StructArmed\Preset\Preset;
use Boundwize\StructArmed\Preset\Presets\DddPreset;
use Boundwize\StructArmed\Preset\Presets\MvcPreset;
use Boundwize\StructArmed\Preset\Presets\Psr12Preset;
use Boundwize\StructArmed\Preset\Presets\Psr15Preset;
use Boundwize\StructArmed\Preset\Presets\Psr1Preset;
use Boundwize\StructArmed\Preset\Presets\Psr4Preset;
use Boundwize\StructArmed\Preset\Presets\YagniPreset;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use Boundwize\StructArmed\Rule\AnonymousFunctionRuleInterface;
use Boundwize\StructArmed\Rule\FileAnalysisRuleInterface;
use Boundwize\StructArmed\Rule\FunctionRuleInterface;
use Boundwize\StructArmed\Rule\Rules\Class_\AnonymousClassMayNotHaveEmptyParenthesesRule;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeFinalRule;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4SourcePathsRule;
use Boundwize\StructArmed\Rule\Rules\File\Psr1PhpTagsRule;
use Boundwize\StructArmed\Rule\Rules\File\Psr1SymbolsOrSideEffectsRule;
use Boundwize\StructArmed\Rule\Rules\File\Psr1Utf8WithoutBomRule;
use Boundwize\StructArmed\Rule\Rules\File\Psr1ValidUtf8Rule;
use Boundwize\StructArmed\Rule\Rules\Layer\MayNotDependOnRule;
use Boundwize\StructArmed\Rule\Rules\Method\MaxMethodLengthRule;
use Boundwize\StructArmed\Rule\Rules\Usage\MayNotUseClassRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Rule\RuleViolationCollection;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function realpath;
use function rename;
use function sort;
use function str_replace;
use function symlink;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(Analyser::class)]
#[CoversClass(ParallelAnalysisNodeExtractor::class)]
#[CoversClass(PhpFileCollector::class)]
#[CoversClass(SkipPathMatcher::class)]
final class AnalyserTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    /**
     * A rule flagging every named function and every anonymous function that
     * accesses a superglobal, implementing both function-like interfaces.
     */
    private function makeNoSuperglobalsInFunctionsRule(): FunctionRuleInterface&AnonymousFunctionRuleInterface
    {
        return new class implements FunctionRuleInterface, AnonymousFunctionRuleInterface {
            public function appliesTo(FunctionNode|AnonymousFunctionNode $node): bool
            {
                return $node->isInLayer('Source');
            }

            public function evaluate(FunctionNode|AnonymousFunctionNode $node): ?RuleViolation
            {
                if (! $node->accessesSuperglobals()) {
                    return null;
                }

                if ($node instanceof FunctionNode) {
                    return new RuleViolation(
                        message:      'Function [' . $node->functionName . '] must not access superglobals',
                        file:         $node->file,
                        line:         $node->line,
                        className:    $node->functionName,
                        layer:        $node->layer,
                        functionName: $node->functionName,
                    );
                }

                return new RuleViolation(
                    message:   $node->getType() . ' in ['
                        . $node->enclosingScopeName()
                        . '] must not access superglobals',
                    file:      $node->file,
                    line:      $node->line,
                    className: $node->enclosingScopeName(),
                    layer:     $node->layer,
                );
            }
        };
    }

    /** @return array<string, string> */
    private function functionRuleProjectFiles(): array
    {
        return [
            'src/helpers.php'      => '<?php' . "\n"
                . 'namespace App;' . "\n"
                . 'function clean(): string { return "clean"; }' . "\n"
                . 'function dirty(): string { return $_GET["x"]; }' . "\n"
                . '$closure = function () { return $_POST["y"]; };' . "\n",
            'src/Handler.php'      => '<?php' . "\n"
                . 'namespace App;' . "\n"
                . 'final class Handler {' . "\n"
                . '    public function handle(): callable { return fn () => $_SERVER["z"]; }' . "\n"
                . '}' . "\n",
            'src/Skipped/skip.php' => '<?php' . "\n"
                . 'namespace App\\Skipped;' . "\n"
                . 'function skipped(): string { return $_GET["x"]; }' . "\n"
                . '$skippedClosure = fn () => $_GET["x"];' . "\n",
        ];
    }

    public function testFunctionRulesSkipNodesTheyDoNotApplyTo(): void
    {
        $basePath = $this->makeTempProject($this->functionRuleProjectFiles() + [
            'other/helpers.php' => '<?php' . "\n"
                . 'namespace Other;' . "\n"
                . 'function dirty(): string { return $_GET["x"]; }' . "\n"
                . '$closure = fn () => $_POST["y"];' . "\n",
        ]);

        // The rule applies to the Source layer only; nodes in Other are seen
        // by the analyser but the rule declines them.
        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->layer('Other', 'other/')
            ->rule('functions.no_superglobals', $this->makeNoSuperglobalsInFunctionsRule())
            ->skip(['functions.no_superglobals' => ['src/Skipped/']]);

        $violations = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule('functions.no_superglobals');

        $this->assertCount(3, $violations);

        foreach ($violations as $violation) {
            $this->assertStringNotContainsString('/other/', $this->normalisePath($violation->file));
        }
    }

    public function testFunctionRulesHonourGlobalSkipPathsForPreResolvedFiles(): void
    {
        $basePath = $this->makeTempProject($this->functionRuleProjectFiles());

        // A caller-supplied file list bypasses file discovery, so a globally
        // skipped file can reach extraction; its nodes must still be skipped.
        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('functions.no_superglobals', $this->makeNoSuperglobalsInFunctionsRule())
            ->skipPaths(['src/Skipped/']);

        $files      = [
            $basePath . '/src/helpers.php',
            $basePath . '/src/Handler.php',
            $basePath . '/src/Skipped/skip.php',
        ];
        $violations = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential(), $files)
            ->forRule('functions.no_superglobals');

        $this->assertCount(3, $violations);

        foreach ($violations as $violation) {
            $this->assertStringNotContainsString('/Skipped/', $this->normalisePath($violation->file));
        }
    }

    public function testFunctionRulesAreEvaluatedAgainstFunctionsAndAnonymousFunctions(): void
    {
        $basePath = $this->makeTempProject($this->functionRuleProjectFiles());

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('functions.no_superglobals', $this->makeNoSuperglobalsInFunctionsRule())
            ->skip(['functions.no_superglobals' => ['src/Skipped/']]);

        foreach ([AnalyserOptions::sequential(), AnalyserOptions::parallel(2)] as $analyserOptions) {
            $violations = (new Analyser($basePath))
                ->analyse($architecture, [], null, $analyserOptions)
                ->forRule('functions.no_superglobals');

            $messages = array_map(
                static fn(RuleViolation $ruleViolation): string => $ruleViolation->message,
                $violations
            );
            sort($messages);

            $this->assertSame([
                'Arrow function in [App\\Handler] must not access superglobals',
                'Closure in [file scope] must not access superglobals',
                'Function [App\\dirty] must not access superglobals',
            ], $messages);

            foreach ($violations as $violation) {
                $this->assertSame('functions.no_superglobals', $violation->ruleKey);
                $this->assertSame('Source', $violation->layer);
                $this->assertFalse($violation->fixable);
            }

            $functionViolation = array_values(array_filter(
                $violations,
                static fn(RuleViolation $ruleViolation): bool => $ruleViolation->functionName !== null
            ));

            $this->assertCount(1, $functionViolation);
            $this->assertSame('App\\dirty', $functionViolation[0]->functionName);
            $this->assertSame(4, $functionViolation[0]->line);
        }
    }

    public function testFunctionRulesHonourGlobalSkipPaths(): void
    {
        $basePath = $this->makeTempProject($this->functionRuleProjectFiles());

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('functions.no_superglobals', $this->makeNoSuperglobalsInFunctionsRule())
            ->skipPaths(['src/helpers.php', 'src/Skipped/']);

        $violations = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule('functions.no_superglobals');

        $this->assertCount(1, $violations);
        $this->assertStringEndsWith('/src/Handler.php', $this->normalisePath($violations[0]->file));
    }

    public function testFunctionRuleViolationsSurviveTheAnalysisNodeCache(): void
    {
        $basePath            = $this->makeTempProject($this->functionRuleProjectFiles());
        $analysisResultCache = new AnalysisResultCache($basePath, new FileHashProvider(), 'cache');

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('functions.no_superglobals', $this->makeNoSuperglobalsInFunctionsRule())
            ->skip(['functions.no_superglobals' => ['src/Skipped/']]);

        $coldViolations = (new Analyser($basePath, $analysisResultCache, 'config'))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule('functions.no_superglobals');
        $warmViolations = (new Analyser($basePath, $analysisResultCache, 'config'))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule('functions.no_superglobals');

        $this->assertCount(3, $coldViolations);
        $this->assertEquals($coldViolations, $warmViolations);
    }

    public function testFunctionRuleViolationsSurviveTheAnalysisNodeCacheWithFileAnalysis(): void
    {
        $basePath            = $this->makeTempProject($this->functionRuleProjectFiles());
        $analysisResultCache = new AnalysisResultCache($basePath, new FileHashProvider(), 'cache');

        // A file-analysis rule makes the warm run load nodes through the
        // file-analysis cache path, which must also restore function-likes.
        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('functions.no_superglobals', $this->makeNoSuperglobalsInFunctionsRule())
            ->rule('psr1.php_tags', new Psr1PhpTagsRule(['src/']))
            ->skip(['functions.no_superglobals' => ['src/Skipped/']]);

        $coldViolations = (new Analyser($basePath, $analysisResultCache, 'config'))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule('functions.no_superglobals');
        $warmViolations = (new Analyser($basePath, $analysisResultCache, 'config'))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule('functions.no_superglobals');

        $this->assertCount(3, $coldViolations);
        $this->assertEquals($coldViolations, $warmViolations);
    }

    /** @return array<string, string> */
    private function anonymousClassRuleProjectFiles(): array
    {
        return [
            'src/Handler.php'       => <<<'PHP'
                <?php
                namespace App;
                final class Handler {
                    public function make(): object { return new class () {}; }
                    public function plain(): object { return new class {}; }
                }

                PHP,
            'src/helpers.php'       => <<<'PHP'
                <?php
                namespace App;
                function make(): object { return new class ( ) {}; }

                PHP,
            'src/Skipped/Loose.php' => <<<'PHP'
                <?php
                $loose = new class () {};

                PHP,
        ];
    }

    public function testAnonymousClassRulesAreEvaluatedAgainstAnonymousClasses(): void
    {
        $basePath = $this->makeTempProject($this->anonymousClassRuleProjectFiles());

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('anonymous_classes.no_parentheses', new AnonymousClassMayNotHaveEmptyParenthesesRule('Source'))
            ->skip(['anonymous_classes.no_parentheses' => ['src/Skipped/']]);

        foreach ([AnalyserOptions::sequential(), AnalyserOptions::parallel(2)] as $analyserOptions) {
            $violations = (new Analyser($basePath))
                ->analyse($architecture, [], null, $analyserOptions)
                ->forRule('anonymous_classes.no_parentheses');

            $messages = array_map(
                static fn(RuleViolation $ruleViolation): string => $ruleViolation->message,
                $violations
            );
            sort($messages);

            $this->assertSame([
                'Anonymous class in [App\\Handler] may not have empty parentheses after `class`',
                'Anonymous class in [App\\make] may not have empty parentheses after `class`',
            ], $messages);

            foreach ($violations as $violation) {
                $this->assertSame('anonymous_classes.no_parentheses', $violation->ruleKey);
                $this->assertSame('Source', $violation->layer);
                $this->assertTrue($violation->fixable);
                $this->assertStringNotContainsString('/Skipped/', $this->normalisePath($violation->file));
            }
        }
    }

    public function testAnonymousClassRuleViolationsSurviveTheAnalysisNodeCache(): void
    {
        $basePath            = $this->makeTempProject($this->anonymousClassRuleProjectFiles());
        $analysisResultCache = new AnalysisResultCache($basePath, new FileHashProvider(), 'cache');

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('anonymous_classes.no_parentheses', new AnonymousClassMayNotHaveEmptyParenthesesRule('Source'));

        $coldViolations = (new Analyser($basePath, $analysisResultCache, 'config'))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule('anonymous_classes.no_parentheses');
        $warmViolations = (new Analyser($basePath, $analysisResultCache, 'config'))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule('anonymous_classes.no_parentheses');

        $this->assertCount(3, $coldViolations);
        $this->assertEquals($coldViolations, $warmViolations);
    }

    public function testSkippedFunctionRuleIsNotEvaluated(): void
    {
        $basePath = $this->makeTempProject($this->functionRuleProjectFiles());

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('functions.no_superglobals', $this->makeNoSuperglobalsInFunctionsRule())
            ->skipRule('functions.no_superglobals');

        $ruleViolationCollection = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential());

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testBuiltInPsr1RulesDoNotRediscoverFilesAfterExtraction(): void
    {
        $basePath = $this->makeTempProject([
            'src/InvalidTag.php'  => '<? echo "invalid tag";',
            'src/InvalidUtf8.php' => "<?php\n// invalid: \xC3\x28\n",
            'src/Bom.php'         => "\xEF\xBB\xBF<?php\n",
            'src/Mixed.php'       => '<?php final class Mixed {} echo "side effect";',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('psr1.tags', new Psr1PhpTagsRule(['src/']))
            ->rule('psr1.utf8', new Psr1ValidUtf8Rule(['src/']))
            ->rule('psr1.bom', new Psr1Utf8WithoutBomRule(['src/']))
            ->rule('psr1.symbols', new Psr1SymbolsOrSideEffectsRule(['src/']));

        $progressHandler = new class ($basePath) implements ProgressHandlerInterface {
            public function __construct(private readonly string $basePath)
            {
            }

            public function start(int $total): void
            {
            }

            public function advance(string $file): void
            {
            }

            public function finish(): void
            {
                rename($this->basePath . '/src', $this->basePath . '/source-moved-after-extraction');
            }
        };

        $ruleViolationCollection = (new Analyser($basePath))->analyse(
            $architecture,
            progressHandler: $progressHandler,
            analyserOptions: AnalyserOptions::sequential(),
        );

        $this->assertCount(1, $ruleViolationCollection->forRule('psr1.tags'));
        $this->assertCount(1, $ruleViolationCollection->forRule('psr1.utf8'));
        $this->assertCount(1, $ruleViolationCollection->forRule('psr1.bom'));
        $this->assertCount(1, $ruleViolationCollection->forRule('psr1.symbols'));
    }

    public function testPsr1RulesKeepIndependentSourcePaths(): void
    {
        $basePath = $this->makeTempProject([
            'src/Invalid.php'   => '<? echo "src";',
            'tests/Invalid.php' => '<? echo "tests";',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', ['src/', 'tests/'])
            ->rule('psr1.src', new Psr1PhpTagsRule(['src/']))
            ->rule('psr1.tests', new Psr1PhpTagsRule(['tests/']));

        $ruleViolationCollection = (new Analyser($basePath))->analyse(
            $architecture,
            analyserOptions: AnalyserOptions::sequential(),
        );

        $sourceViolations = $ruleViolationCollection->forRule('psr1.src');
        $testViolations   = $ruleViolationCollection->forRule('psr1.tests');

        $this->assertCount(1, $sourceViolations);
        $this->assertStringEndsWith('/src/Invalid.php', $this->normalisePath($sourceViolations[0]->file));
        $this->assertCount(1, $testViolations);
        $this->assertStringEndsWith('/tests/Invalid.php', $this->normalisePath($testViolations[0]->file));
    }

    public function testAnalyserReturnsNoViolationsForValidCode(): void
    {
        $architecture = Architecture::define()
            ->layer('Domain', 'tests/Fixtures/sample/src/Domain/')
            ->layer('Application', 'tests/Fixtures/sample/src/Application/')
            ->layer('Infrastructure', 'tests/Fixtures/sample/src/Infrastructure/');

        $analyser                = new Analyser(dirname(__DIR__, 2));
        $ruleViolationCollection = $analyser->analyse($architecture);

        // Order.php is a valid entity — should produce no layer violations
        $this->assertEmpty($ruleViolationCollection->forLayer('Application'));
        $this->assertEmpty($ruleViolationCollection->forLayer('Infrastructure'));
    }

    public function testAnalyserDetectsViolationsInBadCode(): void
    {
        $architecture = Architecture::define()
            ->layer('Domain', 'tests/Fixtures/sample/src/Domain/')
            ->layer('Application', 'tests/Fixtures/sample/src/Application/')
            ->layer('Infrastructure', 'tests/Fixtures/sample/src/Infrastructure/')
            ->withPreset(Preset::DDD());

        $analyser                = new Analyser(dirname(__DIR__, 2));
        $ruleViolationCollection = $analyser->analyse($architecture);

        // BadOrderEntity.php uses DateTime and is not final — should have violations
        $this->assertTrue($ruleViolationCollection->hasViolations());
    }

    public function testDddPresetRejectsDoctrineEntityRepositoryInheritanceOnlyInDomain(): void
    {
        $basePath = $this->makeTempProject([
            'src/Domain/Order/OrderStore.php'                       => <<<'PHP'
                <?php

                namespace App\Domain\Order;

                use Doctrine\ORM\EntityRepository;

                final class OrderStore extends EntityRepository
                {
                }
                PHP,
            'src/Infrastructure/Persistence/DoctrineOrderStore.php' => <<<'PHP'
                <?php

                namespace App\Infrastructure\Persistence;

                use Doctrine\ORM\EntityRepository;

                final class DoctrineOrderStore extends EntityRepository
                {
                }
                PHP,
        ]);

        $violations = (new Analyser($basePath))
            ->analyse(
                Architecture::define()->withPreset(Preset::DDD()),
                analyserOptions: AnalyserOptions::sequential(),
            )
            ->forRule(DddPreset::DOMAIN_MUST_NOT_EXTEND_DOCTRINE_ENTITY_REPOSITORY);

        $this->assertCount(1, $violations);
        $this->assertSame('App\\Domain\\Order\\OrderStore', $violations[0]->className);
        $this->assertSame(
            'Class [App\\Domain\\Order\\OrderStore] must not extend class [Doctrine\\ORM\\EntityRepository]',
            $violations[0]->message
        );
    }

    public function testAnalyserCollectsClassNodesWithSequentialRunner(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php' => '<?php namespace App; class Foo {}',
            'src/Bar.php' => '<?php namespace App; class Bar {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential());

        $this->assertCount(2, $ruleViolationCollection->forRule('source.must_be_final'));
    }

    public function testMustBeFinalRuleDoesNotFlagClassExtendedByAnotherScannedClass(): void
    {
        $basePath = $this->makeTempProject([
            'src/BaseHandler.php'    => '<?php namespace App; class BaseHandler {}',
            'src/OrderHandler.php'   => '<?php namespace App; final class OrderHandler extends BaseHandler {}',
            'src/PaymentHandler.php' => '<?php namespace App; class PaymentHandler {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $violations = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule('source.must_be_final');

        // BaseHandler is extended (must stay non-final); PaymentHandler is the
        // only genuinely non-final leaf class.
        $this->assertCount(1, $violations);
        $this->assertSame('App\PaymentHandler', $violations[0]->className);
    }

    public function testMustBeFinalRuleRecognizesExtendedClassCaseInsensitively(): void
    {
        $basePath = $this->makeTempProject([
            'src/Entities.php' => <<<'PHP'
                <?php

                namespace App\Domain;

                class BaseEntity
                {
                }

                class ChildEntity extends baseentity
                {
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('Domain', 'src/')
            ->rule(
                'domain.base_must_be_final',
                new MustBeFinalRule(layer: 'Domain', classNamePattern: '/BaseEntity$/'),
            );

        $violations = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule('domain.base_must_be_final');

        $this->assertCount(0, $violations);
    }

    public function testMustBeFinalRuleDoesNotFlagClassExtendedByAnonymousClass(): void
    {
        $factory = '<?php namespace App;' . "\n"
            . 'final class HandlerFactory {' . "\n"
            . '    public function make(): BaseHandler { return new class extends BaseHandler {}; }' . "\n"
            . '}';

        $basePath = $this->makeTempProject([
            'src/BaseHandler.php'    => '<?php namespace App; class BaseHandler {}',
            'src/HandlerFactory.php' => $factory,
            'src/PaymentHandler.php' => '<?php namespace App; class PaymentHandler {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $violations = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule('source.must_be_final');

        // BaseHandler is extended by an anonymous class (must stay non-final);
        // PaymentHandler is the only genuinely non-final leaf class.
        $this->assertCount(1, $violations);
        $this->assertSame('App\PaymentHandler', $violations[0]->className);
    }

    public function testMustBeFinalRuleDoesNotFlagClassExtendedByTopLevelAnonymousClass(): void
    {
        // Migration-style file: no named class at all, only a returned anonymous class.
        $registration = '<?php use App\BaseHandler;' . "\n"
            . 'return new class extends BaseHandler {};';

        $basePath = $this->makeTempProject([
            'src/BaseHandler.php'          => '<?php namespace App; class BaseHandler {}',
            'src/handler_registration.php' => $registration,
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $violations = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule('source.must_be_final');

        $this->assertCount(0, $violations);
    }

    public function testMustBeFinalRuleDoesNotFlagClassExtendedByAnonymousClassWithParallelRunner(): void
    {
        $factory = '<?php namespace App;' . "\n"
            . 'final class HandlerFactory {' . "\n"
            . '    public function make(): BaseHandler { return new class extends BaseHandler {}; }' . "\n"
            . '}';

        $basePath = $this->makeTempProject([
            'src/BaseHandler.php'    => '<?php namespace App; class BaseHandler {}',
            'src/HandlerFactory.php' => $factory,
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $violations = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::parallel())
            ->forRule('source.must_be_final');

        $this->assertCount(0, $violations);
    }

    public function testMustBeFinalRuleDoesNotFlagClassExtendedByAnonymousClassOnCachedRun(): void
    {
        $factory = '<?php namespace App;' . "\n"
            . 'final class HandlerFactory {' . "\n"
            . '    public function make(): BaseHandler { return new class extends BaseHandler {}; }' . "\n"
            . '}';

        $basePath            = $this->makeTempProject([
            'src/BaseHandler.php'    => '<?php namespace App; class BaseHandler {}',
            'src/HandlerFactory.php' => $factory,
        ]);
        $analysisResultCache = new AnalysisResultCache($basePath, new FileHashProvider(), 'cache');

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $coldViolations = (new Analyser($basePath, $analysisResultCache, 'config'))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule('source.must_be_final');
        $warmViolations = (new Analyser($basePath, $analysisResultCache, 'config'))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule('source.must_be_final');

        // The anonymous-class parent must survive the class-node cache round-trip.
        $this->assertCount(0, $coldViolations);
        $this->assertCount(0, $warmViolations);
    }

    public function testMustBeFinalRuleDoesNotFlagClassExtendedByAnonymousClassOnCachedRunWithFileAnalysis(): void
    {
        $factory = '<?php namespace App;' . "\n"
            . 'final class HandlerFactory {' . "\n"
            . '    public function make(): BaseHandler { return new class extends BaseHandler {}; }' . "\n"
            . '}';

        $basePath            = $this->makeTempProject([
            'src/BaseHandler.php'    => '<?php namespace App; class BaseHandler {}',
            'src/HandlerFactory.php' => $factory,
        ]);
        $analysisResultCache = new AnalysisResultCache($basePath, new FileHashProvider(), 'cache');

        // A file-analysis rule makes the warm run load class nodes through the
        // file-analysis cache path, which must also restore anonymous class nodes.
        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('source.must_be_final', new MustBeFinalRule('Source'))
            ->rule('psr1.php_tags', new Psr1PhpTagsRule(['src/']));

        $coldViolations = (new Analyser($basePath, $analysisResultCache, 'config'))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule('source.must_be_final');
        $warmViolations = (new Analyser($basePath, $analysisResultCache, 'config'))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule('source.must_be_final');

        $this->assertCount(0, $coldViolations);
        $this->assertCount(0, $warmViolations);
    }

    public function testMustBeFinalRuleFlagsExtendedClassWhenChildIsOutsideScannedPaths(): void
    {
        $order = '<?php namespace Consumer; use App\BaseHandler;' . "\n"
            . 'final class OrderHandler extends BaseHandler {}';

        $basePath = $this->makeTempProject([
            'src/BaseHandler.php'       => '<?php namespace App; class BaseHandler {}',
            'consumer/OrderHandler.php' => $order,
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $violations = (new Analyser($basePath))
            ->analyse($architecture, ['src/'], null, AnalyserOptions::sequential())
            ->forRule('source.must_be_final');

        // The extending class lives outside the scanned paths, so BaseHandler is
        // reported as if not extended — a false positive on purpose.
        $this->assertCount(1, $violations);
        $this->assertSame('App\BaseHandler', $violations[0]->className);
    }

    public function testYagniPresetReportsOnlyUnusedAbstractions(): void
    {
        $consumer = '<?php namespace App;' . "\n"
            . 'final class Consumer extends UsedBase implements UsedInterface { use UsedTrait; }';

        $basePath = $this->makeTempProject([
            'src/UsedInterface.php'   => '<?php namespace App; interface UsedInterface {}',
            'src/UnusedInterface.php' => '<?php namespace App; interface UnusedInterface {}',
            'src/BaseInterface.php'   => '<?php namespace App; interface BaseInterface {}',
            'src/ChildInterface.php'  => '<?php namespace App; interface ChildInterface extends BaseInterface {}',
            'src/UsedBase.php'        => '<?php namespace App; abstract class UsedBase {}',
            'src/UnusedBase.php'      => '<?php namespace App; abstract class UnusedBase {}',
            'src/UsedTrait.php'       => '<?php namespace App; trait UsedTrait {}',
            'src/UnusedTrait.php'     => '<?php namespace App; trait UnusedTrait {}',
            'src/Consumer.php'        => $consumer,
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::YAGNI(sourcePaths: ['src/']));

        $ruleViolationCollection = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential());

        $interfaceViolations = $ruleViolationCollection->forRule(YagniPreset::INTERFACE_MUST_BE_USED);
        $abstractViolations  = $ruleViolationCollection->forRule(YagniPreset::ABSTRACT_CLASS_MUST_BE_USED);
        $traitViolations     = $ruleViolationCollection->forRule(YagniPreset::TRAIT_MUST_BE_USED);

        // BaseInterface is extended by ChildInterface, so only UnusedInterface
        // and the never-implemented ChildInterface itself are reported.
        $interfaceClassNames = array_map(
            static fn (RuleViolation $ruleViolation): string => $ruleViolation->className,
            $interfaceViolations
        );
        sort($interfaceClassNames);

        $this->assertSame(['App\ChildInterface', 'App\UnusedInterface'], $interfaceClassNames);

        $this->assertCount(1, $abstractViolations);
        $this->assertSame('App\UnusedBase', $abstractViolations[0]->className);

        $this->assertCount(1, $traitViolations);
        $this->assertSame('App\UnusedTrait', $traitViolations[0]->className);
    }

    public function testMustBeUsedInterfaceRuleRecognizesTransitiveImplementation(): void
    {
        $basePath = $this->makeTempProject([
            'src/BaseInterface.php'  => '<?php namespace App; interface BaseInterface {}',
            'src/ChildInterface.php' => '<?php namespace App; interface ChildInterface extends BaseInterface {}',
            'src/Consumer.php'       => '<?php namespace App; final class Consumer implements ChildInterface {}',
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::YAGNI(sourcePaths: ['src/']));

        $ruleViolationCollection = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential());

        // Consumer implements ChildInterface, which transitively implements
        // BaseInterface — neither interface is speculative.
        $this->assertCount(0, $ruleViolationCollection->forRule(YagniPreset::INTERFACE_MUST_BE_USED));
    }

    public function testMustBeUsedTraitRuleRecognizesTraitUsedByAnotherTrait(): void
    {
        $basePath = $this->makeTempProject([
            'src/InnerTrait.php' => '<?php namespace App; trait InnerTrait {}',
            'src/OuterTrait.php' => '<?php namespace App; trait OuterTrait { use InnerTrait; }',
            'src/Consumer.php'   => '<?php namespace App; final class Consumer { use OuterTrait; }',
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::YAGNI(sourcePaths: ['src/']));

        $violations = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule(YagniPreset::TRAIT_MUST_BE_USED);

        // InnerTrait is used by OuterTrait, which is used by Consumer.
        $this->assertCount(0, $violations);
    }

    public function testMustBeUsedTraitRuleRecognizesTraitUsedByEnum(): void
    {
        $basePath = $this->makeTempProject([
            'src/Helper.php' => '<?php namespace App; trait Helper {}',
            'src/Status.php' => '<?php namespace App; enum Status { use Helper; }',
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::YAGNI(sourcePaths: ['src/']));

        $violations = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule(YagniPreset::TRAIT_MUST_BE_USED);

        $this->assertCount(0, $violations);
    }

    public function testYagniRulesDoNotFlagAbstractionsReferencedAsDependencies(): void
    {
        $checker = '<?php namespace App;' . "\n"
            . 'final class Checker {' . "\n"
            . '    public function check(object $obj): bool { return $obj instanceof Contract; }' . "\n"
            . '    public function handle(AbstractHandler $handler): void {}' . "\n"
            . '    public function helperName(): string { return Helper::class; }' . "\n"
            . '}';

        $basePath = $this->makeTempProject([
            'src/Contract.php'        => '<?php namespace App; interface Contract {}',
            'src/AbstractHandler.php' => '<?php namespace App; abstract class AbstractHandler {}',
            'src/Helper.php'          => '<?php namespace App; trait Helper {}',
            'src/Checker.php'         => $checker,
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::YAGNI(sourcePaths: ['src/']));

        $ruleViolationCollection = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential());

        // instanceof checks, type hints, and ::class constants are references;
        // removing the abstraction would break the referencing code.
        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testYagniRulesDoNotFlagInterfaceReferencedByLeadingBackslashString(): void
    {
        $basePath = $this->makeTempProject([
            'src/Contract.php'  => '<?php namespace App; interface Contract {}',
            'src/bootstrap.php' => '<?php namespace App; interface_exists(\'\\App\\Contract\');',
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::YAGNI(sourcePaths: ['src/']));

        $ruleViolationCollection = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential());

        // '\App\Contract' names the interface just like 'App\Contract' does;
        // the reference must keep it from being reported (and removed) as unused.
        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testYagniRulesIgnoreSelfReferences(): void
    {
        $basePath = $this->makeTempProject([
            'src/UnusedInterface.php' => '<?php namespace App;'
                . ' interface UnusedInterface { public const NAME = UnusedInterface::class; }',
            'src/UnusedBase.php'      => '<?php namespace App; abstract class UnusedBase'
                . ' { public function name(): string { return UnusedBase::class; } }',
            'src/UnusedTrait.php'     => '<?php namespace App; trait UnusedTrait'
                . ' { public function name(): string { return UnusedTrait::class; } }',
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::YAGNI(sourcePaths: ['src/']));

        $ruleViolationCollection = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential());

        $interfaceViolations = $ruleViolationCollection->forRule(YagniPreset::INTERFACE_MUST_BE_USED);
        $abstractViolations  = $ruleViolationCollection->forRule(YagniPreset::ABSTRACT_CLASS_MUST_BE_USED);
        $traitViolations     = $ruleViolationCollection->forRule(YagniPreset::TRAIT_MUST_BE_USED);

        // A class-like referencing itself cannot keep itself alive.
        $this->assertCount(1, $interfaceViolations);
        $this->assertSame('App\UnusedInterface', $interfaceViolations[0]->className);
        $this->assertCount(1, $abstractViolations);
        $this->assertSame('App\UnusedBase', $abstractViolations[0]->className);
        $this->assertCount(1, $traitViolations);
        $this->assertSame('App\UnusedTrait', $traitViolations[0]->className);
    }

    public function testExtendedClassMustBeAbstractOrInstantiatedRuleFlagsUninstantiatedParent(): void
    {
        $basePath = $this->makeTempProject([
            'src/BaseRepository.php' => '<?php namespace App; class BaseRepository {}',
            'src/UserRepository.php' => '<?php namespace App;'
                . ' final class UserRepository extends BaseRepository {}',
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::YAGNI(sourcePaths: ['src/']));

        $violations = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule(YagniPreset::EXTENDED_CLASS_MUST_BE_ABSTRACT_OR_INSTANTIATED);

        // BaseRepository is only ever used as a parent class; extending it
        // does not count as a reference, so it should be abstract.
        $this->assertCount(1, $violations);
        $this->assertSame('App\BaseRepository', $violations[0]->className);
    }

    public function testExtendedClassMustBeAbstractOrInstantiatedRuleFlagsTypeHintedButUninstantiatedParent(): void
    {
        $consumer = '<?php namespace App;' . "\n"
            . 'final class Consumer { public function handle(BaseRepository $repository): string'
            . ' { return $repository instanceof BaseRepository ? BaseRepository::class : \'\'; } }';

        $basePath = $this->makeTempProject([
            'src/BaseRepository.php' => '<?php namespace App; class BaseRepository {}',
            'src/UserRepository.php' => '<?php namespace App;'
                . ' final class UserRepository extends BaseRepository {}',
            'src/Consumer.php'       => $consumer,
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::YAGNI(sourcePaths: ['src/']));

        $violations = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule(YagniPreset::EXTENDED_CLASS_MUST_BE_ABSTRACT_OR_INSTANTIATED);

        // Type hints, instanceof, and ::class keep working on an abstract
        // class — only instantiation requires it to stay concrete.
        $this->assertCount(1, $violations);
        $this->assertSame('App\BaseRepository', $violations[0]->className);
    }

    public function testExtendedClassMustBeAbstractOrInstantiatedRulePassesOnTraitNewParent(): void
    {
        $basePath = $this->makeTempProject([
            'src/ParentClass.php' => '<?php namespace App; class ParentClass {}',
            'src/Factory.php'     => '<?php namespace App; trait Factory'
                . ' { public static function createParent(): object { return new parent(); } }',
            'src/ChildClass.php'  => '<?php namespace App; class ChildClass extends ParentClass { use Factory; }',
            'src/Unrelated.php'   => '<?php namespace App; class Unrelated {}',
            'src/Leaf.php'        => '<?php namespace App; final class Leaf extends Unrelated {}',
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::YAGNI(sourcePaths: ['src/']));

        $violations = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule(YagniPreset::EXTENDED_CLASS_MUST_BE_ABSTRACT_OR_INSTANTIATED);

        // `new parent()` in a trait instantiates the parent of the class using
        // the trait; a parent whose children do not use the trait is unaffected.
        $this->assertCount(1, $violations);
        $this->assertSame('App\Unrelated', $violations[0]->className);
    }

    public function testExtendedClassMustBeAbstractOrInstantiatedRuleResolvesTraitNewParentTransitively(): void
    {
        $basePath = $this->makeTempProject([
            'src/ParentClass.php' => '<?php namespace App; class ParentClass {}',
            'src/Factory.php'     => '<?php namespace App; trait Factory'
                . ' { public static function createParent(): object { return new parent(); } }',
            'src/Outer.php'       => '<?php namespace App; trait Outer { use Factory;'
                . ' public static function again(): object { return new parent(); } }',
            'src/ChildClass.php'  => '<?php namespace App; class ChildClass extends ParentClass { use Outer; }',
            'src/OtherBase.php'   => '<?php namespace App; class OtherBase {}',
            'src/builder.php'     => '<?php namespace App;'
                . ' function build(): object { return new class extends OtherBase { use Factory; }; }',
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::YAGNI(sourcePaths: ['src/']));

        $violations = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::parallel())
            ->forRule(YagniPreset::EXTENDED_CLASS_MUST_BE_ABSTRACT_OR_INSTANTIATED);

        $this->assertCount(0, $violations);
    }

    public function testExtendedClassMustBeAbstractOrInstantiatedRuleIgnoresTraitNewParentWhenTraitIsUnused(): void
    {
        $basePath = $this->makeTempProject([
            'src/ParentClass.php' => '<?php namespace App; class ParentClass {}',
            'src/Factory.php'     => '<?php namespace App; trait Factory'
                . ' { public static function createParent(): object { return new parent(); } }',
            'src/ChildClass.php'  => '<?php namespace App; final class ChildClass extends ParentClass {}',
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::YAGNI(sourcePaths: ['src/']));

        $ruleViolationCollection = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential());

        // A trait nobody uses has no parent to instantiate: the extended class
        // is still flagged, and so is the unused trait itself.
        $extendedViolations = $ruleViolationCollection
            ->forRule(YagniPreset::EXTENDED_CLASS_MUST_BE_ABSTRACT_OR_INSTANTIATED);
        $traitViolations    = $ruleViolationCollection->forRule(YagniPreset::TRAIT_MUST_BE_USED);

        $this->assertCount(1, $extendedViolations);
        $this->assertSame('App\ParentClass', $extendedViolations[0]->className);
        $this->assertCount(1, $traitViolations);
        $this->assertSame('App\Factory', $traitViolations[0]->className);
    }

    public function testExtendedClassMustBeAbstractOrInstantiatedRuleFlagsBaseWhenTraitNewParentUserHasNoParent(): void
    {
        $basePath = $this->makeTempProject([
            'src/Base.php'    => '<?php namespace App; class Base {}',
            'src/Factory.php' => '<?php namespace App; trait Factory'
                . ' { public static function createParent(): object { return new parent(); } }',
            'src/Bar.php'     => '<?php namespace App; final class Bar { use Factory; }',
            'src/Baz.php'     => '<?php namespace App; final class Baz extends Base {}',
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::YAGNI(sourcePaths: ['src/']));

        $ruleViolationCollection = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential());

        // Bar has no parent, so the trait's `new parent()` instantiates
        // nothing; Base stays an uninstantiated extended class.
        $violations = $ruleViolationCollection->forRule(YagniPreset::EXTENDED_CLASS_MUST_BE_ABSTRACT_OR_INSTANTIATED);

        $this->assertCount(1, $violations);
        $this->assertSame('App\\Base', $violations[0]->className);
    }

    public function testExtendedClassMustBeAbstractOrInstantiatedRuleResolvesParentInsideAnonymousClassInTrait(): void
    {
        $factory = '<?php namespace App;' . "\n"
            . 'trait Factory {' . "\n"
            . '    public static function create(): object {' . "\n"
            . '        return new class extends Base {' . "\n"
            . '            public static function createParent(): object { return new parent(); }' . "\n"
            . '        };' . "\n"
            . '    }' . "\n"
            . '}';

        $basePath = $this->makeTempProject([
            'src/Base.php'    => '<?php namespace App; class Base {}',
            'src/Other.php'   => '<?php namespace App; class Other {}',
            'src/Factory.php' => $factory,
            'src/Host.php'    => '<?php namespace App; final class Host extends Other { use Factory; }',
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::YAGNI(sourcePaths: ['src/']));

        $violations = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule(YagniPreset::EXTENDED_CLASS_MUST_BE_ABSTRACT_OR_INSTANTIATED);

        // `new parent()` belongs to the anonymous class, so it instantiates
        // Base — not Other, the parent of the class using the trait.
        $this->assertCount(1, $violations);
        $this->assertSame('App\\Other', $violations[0]->className);
    }

    /**
     * @param array<string, string> $files
     * @param list<string>          $expectedExtendedViolations
     * @param list<string>          $expectedTraitViolations
     * @param list<string>          $expectedAbstractClassViolations
     */
    #[DataProvider('selfAndStaticInstantiationProvider')]
    public function testResolvesSelfAndStaticInstantiationsByPhpBinding(
        array $files,
        array $expectedExtendedViolations,
        array $expectedTraitViolations = [],
        array $expectedAbstractClassViolations = [],
    ): void {
        $basePath = $this->makeTempProject($files);

        $architecture = Architecture::define()
            ->withPreset(Preset::YAGNI(sourcePaths: ['src/']));

        // Sequential and parallel analysis must resolve the deferred markers
        // identically.
        foreach ([AnalyserOptions::sequential(), AnalyserOptions::parallel()] as $analyserOptions) {
            $ruleViolationCollection = (new Analyser($basePath))
                ->analyse($architecture, [], null, $analyserOptions);

            $this->assertSame(
                $expectedExtendedViolations,
                $this->violationClassNames(
                    $ruleViolationCollection->forRule(YagniPreset::EXTENDED_CLASS_MUST_BE_ABSTRACT_OR_INSTANTIATED)
                )
            );
            $this->assertSame(
                $expectedTraitViolations,
                $this->violationClassNames($ruleViolationCollection->forRule(YagniPreset::TRAIT_MUST_BE_USED))
            );
            $this->assertSame(
                $expectedAbstractClassViolations,
                $this->violationClassNames(
                    $ruleViolationCollection->forRule(YagniPreset::ABSTRACT_CLASS_MUST_BE_USED)
                )
            );
        }
    }

    /**
     * @return iterable<string, array{0: array<string, string>, 1: list<string>, 2?: list<string>, 3?: list<string>}>
     */
    public static function selfAndStaticInstantiationProvider(): iterable
    {
        $selfFactory   = '<?php namespace App; trait Factory'
            . ' { public static function create(): object { return new self(); } }';
        $staticFactory = '<?php namespace App; trait Factory'
            . ' { public static function create(): object { return new static(); } }';

        yield 'unused trait with new self() is still reported unused' => [
            [
                'src/Factory.php' => $selfFactory,
                'src/A.php'       => '<?php namespace App; class A {}',
                'src/B.php'       => '<?php namespace App; final class B extends A {}',
            ],
            ['App\A'],
            ['App\Factory'],
        ];

        yield 'unused trait with new static() is still reported unused' => [
            [
                'src/Factory.php' => $staticFactory,
                'src/A.php'       => '<?php namespace App; class A {}',
                'src/B.php'       => '<?php namespace App; final class B extends A {}',
            ],
            ['App\A'],
            ['App\Factory'],
        ];

        // `self` is lexically bound to the composing class: A is instantiated,
        // but Child — which merely inherits create() — is not.
        yield 'trait new self() marks the consuming class but not its descendants' => [
            [
                'src/Factory.php' => $selfFactory,
                'src/A.php'       => '<?php namespace App; class A { use Factory; }',
                'src/Child.php'   => '<?php namespace App; class Child extends A {}',
                'src/Leaf.php'    => '<?php namespace App; final class Leaf extends Child {}',
            ],
            ['App\Child'],
        ];

        yield 'trait new self() marks every consuming class' => [
            [
                'src/Factory.php' => $selfFactory,
                'src/A.php'       => '<?php namespace App; class A { use Factory; }',
                'src/B.php'       => '<?php namespace App; class B { use Factory; }',
                'src/ChildA.php'  => '<?php namespace App; final class ChildA extends A {}',
                'src/ChildB.php'  => '<?php namespace App; final class ChildB extends B {}',
            ],
            [],
        ];

        yield 'trait new self() resolves through transitive trait composition' => [
            [
                'src/Factory.php' => $selfFactory,
                'src/Outer.php'   => '<?php namespace App; trait Outer { use Factory; }',
                'src/A.php'       => '<?php namespace App; class A { use Outer; }',
                'src/Child.php'   => '<?php namespace App; final class Child extends A {}',
            ],
            [],
        ];

        // Outer1 and Outer2 both lead to Top: the shared trait is visited once.
        yield 'trait new self() resolves through diamond trait composition' => [
            [
                'src/Factory.php' => $selfFactory,
                'src/Outer1.php'  => '<?php namespace App; trait Outer1 { use Factory; }',
                'src/Outer2.php'  => '<?php namespace App; trait Outer2 { use Factory; }',
                'src/Top.php'     => '<?php namespace App; trait Top { use Outer1; use Outer2; }',
                'src/A.php'       => '<?php namespace App; class A { use Top; }',
                'src/Child.php'   => '<?php namespace App; final class Child extends A {}',
            ],
            [],
        ];

        // An anonymous class using the trait has no name to instantiate.
        yield 'trait new self() used by an anonymous class marks nothing' => [
            [
                'src/Factory.php' => $selfFactory,
                'src/Host.php'    => '<?php namespace App; final class Host'
                    . ' { public function make(): object { return new class { use Factory; }; } }',
                'src/A.php'       => '<?php namespace App; class A {}',
                'src/Child.php'   => '<?php namespace App; final class Child extends A {}',
            ],
            ['App\\A'],
        ];

        // `new self()` in an abstract class can never succeed, in the class
        // or through a trait: the class stays an unused abstraction.
        yield 'class new self() does not mark an abstract declaring class' => [
            [
                'src/A.php' => '<?php namespace App; abstract class A'
                    . ' { public static function create(): object { return new self(); } }',
            ],
            [],
            [],
            ['App\\A'],
        ];

        yield 'trait new self() does not mark an abstract consuming class' => [
            [
                'src/Factory.php' => $selfFactory,
                'src/A.php'       => '<?php namespace App; abstract class A { use Factory; }',
            ],
            [],
            [],
            ['App\\A'],
        ];

        yield 'class new self() marks the declaring class only' => [
            [
                'src/ParentClass.php' => '<?php namespace App; class ParentClass'
                    . ' { public static function create(): object { return new self(); } }',
                'src/Child.php'       => '<?php namespace App; class Child extends ParentClass {}',
                'src/Leaf.php'        => '<?php namespace App; final class Leaf extends Child {}',
            ],
            ['App\Child'],
        ];

        // `static` is late-bound: every class inheriting create() can be the
        // instantiated one. An unrelated hierarchy is unaffected.
        yield 'trait new static() marks consuming classes and their descendants' => [
            [
                'src/Factory.php' => $staticFactory,
                'src/A.php'       => '<?php namespace App; class A { use Factory; }',
                'src/B.php'       => '<?php namespace App; class B extends A {}',
                'src/C.php'       => '<?php namespace App; final class C extends B {}',
                'src/X.php'       => '<?php namespace App; class X { use Factory; }',
                'src/Y.php'       => '<?php namespace App; final class Y extends X {}',
                'src/U.php'       => '<?php namespace App; class U {}',
                'src/V.php'       => '<?php namespace App; final class V extends U {}',
            ],
            ['App\U'],
        ];

        yield 'trait new static() resolves through transitive trait composition' => [
            [
                'src/Factory.php' => $staticFactory,
                'src/Outer.php'   => '<?php namespace App; trait Outer { use Factory; }',
                'src/A.php'       => '<?php namespace App; class A { use Outer; }',
                'src/B.php'       => '<?php namespace App; class B extends A {}',
                'src/C.php'       => '<?php namespace App; final class C extends B {}',
            ],
            [],
        ];

        yield 'class new static() marks the declaring class and its descendants' => [
            [
                'src/A.php' => '<?php namespace App; class A'
                    . ' { public static function create(): object { return new static(); } }',
                'src/B.php' => '<?php namespace App; class B extends A {}',
                'src/C.php' => '<?php namespace App; final class C extends B {}',
            ],
            [],
        ];

        // An abstract descendant cannot be a `new static()` target, so it is
        // neither instantiated nor referenced; C, below it, still is.
        yield 'class new static() skips abstract descendants' => [
            [
                'src/A.php' => '<?php namespace App; class A'
                    . ' { public static function create(): object { return new static(); } }',
                'src/B.php' => '<?php namespace App; abstract class B extends A {}',
                'src/C.php' => '<?php namespace App; final class C extends B {}',
                'src/D.php' => '<?php namespace App; abstract class D extends A {}',
            ],
            [],
            [],
            ['App\\D'],
        ];

        // `new static()` in an abstract class without concrete descendants
        // can never instantiate anything, in the class or through a trait.
        yield 'class new static() does not mark an abstract declaring class' => [
            [
                'src/A.php' => '<?php namespace App; abstract class A'
                    . ' { public static function create(): object { return new static(); } }',
            ],
            [],
            [],
            ['App\\A'],
        ];

        yield 'trait new static() does not mark an abstract consuming class' => [
            [
                'src/Factory.php' => $staticFactory,
                'src/A.php'       => '<?php namespace App; abstract class A { use Factory; }',
            ],
            [],
            [],
            ['App\\A'],
        ];

        yield 'new (self::class)() follows self binding' => [
            [
                'src/Factory.php' => '<?php namespace App; trait Factory'
                    . ' { public static function create(): object { return new (self::class)(); } }',
                'src/A.php'       => '<?php namespace App; class A { use Factory; }',
                'src/Child.php'   => '<?php namespace App; class Child extends A {}',
                'src/Leaf.php'    => '<?php namespace App; final class Leaf extends Child {}',
            ],
            ['App\Child'],
        ];

        yield 'new (static::class)() follows static binding' => [
            [
                'src/Factory.php' => '<?php namespace App; trait Factory'
                    . ' { public static function create(): object { return new (static::class)(); } }',
                'src/A.php'       => '<?php namespace App; class A { use Factory; }',
                'src/B.php'       => '<?php namespace App; class B extends A {}',
                'src/C.php'       => '<?php namespace App; final class C extends B {}',
            ],
            [],
        ];

        // self/static inside an anonymous class belong to that class, which
        // has no name to instantiate: neither the trait's consumer nor Base
        // is marked instantiated.
        yield 'anonymous class self/static inside a trait stay isolated' => [
            [
                'src/Base.php'    => '<?php namespace App; class Base {}',
                'src/Factory.php' => '<?php namespace App; trait Factory {' . "\n"
                    . '    public function make(): object {' . "\n"
                    . '        return new class extends Base {' . "\n"
                    . '            public function x(): object { return new self(); }' . "\n"
                    . '            public function y(): object { return new static(); }' . "\n"
                    . '        };' . "\n"
                    . '    }' . "\n"
                    . '}',
                'src/A.php'       => '<?php namespace App; class A { use Factory; }',
                'src/Child.php'   => '<?php namespace App; final class Child extends A {}',
            ],
            ['App\A', 'App\Base'],
        ];

        yield 'trait new parent() still marks the parent of the consuming class' => [
            [
                'src/ParentClass.php' => '<?php namespace App; class ParentClass {}',
                'src/Factory.php'     => '<?php namespace App; trait Factory'
                    . ' { public static function create(): object { return new parent(); } }',
                'src/Child.php'       => '<?php namespace App; class Child extends ParentClass { use Factory; }',
                'src/Leaf.php'        => '<?php namespace App; final class Leaf extends Child {}',
            ],
            ['App\Child'],
        ];
    }

    /**
     * @param array<RuleViolation> $violations
     * @return list<string>
     */
    private function violationClassNames(array $violations): array
    {
        $classNames = array_map(
            static fn (RuleViolation $ruleViolation): string =>
                $ruleViolation->className,
            $violations
        );
        sort($classNames);

        return $classNames;
    }

    public function testExtendedClassMustBeAbstractOrInstantiatedRulePassesWhenParentIsInstantiated(): void
    {
        $factory = '<?php namespace App;' . "\n"
            . 'final class Factory { public function make(): BaseRepository { return new BaseRepository(); } }';

        $basePath = $this->makeTempProject([
            'src/BaseRepository.php' => '<?php namespace App; class BaseRepository {}',
            'src/UserRepository.php' => '<?php namespace App;'
                . ' final class UserRepository extends BaseRepository {}',
            'src/Factory.php'        => $factory,
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::YAGNI(sourcePaths: ['src/']));

        $violations = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule(YagniPreset::EXTENDED_CLASS_MUST_BE_ABSTRACT_OR_INSTANTIATED);

        $this->assertCount(0, $violations);
    }

    public function testExtendedClassMustBeAbstractOrInstantiatedRulePassesWhenChildInstantiatesParent(): void
    {
        // The extends clause itself must not count as a reference, but the
        // child's `new BaseRepository()` must: making the parent abstract
        // would fatal at that instantiation.
        $child = '<?php namespace App;' . "\n"
            . 'final class CachingRepository extends BaseRepository {' . "\n"
            . '    public function inner(): BaseRepository { return new BaseRepository(); }' . "\n"
            . '}';

        $basePath = $this->makeTempProject([
            'src/BaseRepository.php'    => '<?php namespace App; class BaseRepository {}',
            'src/CachingRepository.php' => $child,
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::YAGNI(sourcePaths: ['src/']));

        $violations = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule(YagniPreset::EXTENDED_CLASS_MUST_BE_ABSTRACT_OR_INSTANTIATED);

        $this->assertCount(0, $violations);
    }

    public function testExtendedClassMustBeAbstractOrInstantiatedRulePassesOnClassExpressionInstantiation(): void
    {
        // The constant class expression resolves at the `new` site itself.
        $factory = '<?php namespace App;' . "\n"
            . 'final class Factory { public function make(): object {'
            . ' return new (BaseRepository::class)(); } }';

        $basePath = $this->makeTempProject([
            'src/BaseRepository.php' => '<?php namespace App; class BaseRepository {}',
            'src/UserRepository.php' => '<?php namespace App;'
                . ' final class UserRepository extends BaseRepository {}',
            'src/Factory.php'        => $factory,
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::YAGNI(sourcePaths: ['src/']));

        $violations = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule(YagniPreset::EXTENDED_CLASS_MUST_BE_ABSTRACT_OR_INSTANTIATED);

        $this->assertCount(0, $violations);
    }

    public function testExtendedClassMustBeAbstractOrInstantiatedRuleFlagsFactoryFedParent(): void
    {
        // `make(Base::class)` cannot be connected to the factory's
        // `new $class` statically — runtime-fed construction is part of the
        // documented scanned-code boundary, handled with skipRule() or skip
        // paths, so Base is still reported here.
        $functions = '<?php namespace App;' . "\n"
            . 'function make(string $class): object { return new $class(); }' . "\n"
            . 'make(Base::class);';

        $basePath = $this->makeTempProject([
            'src/Base.php'      => '<?php namespace App; class Base {}',
            'src/Child.php'     => '<?php namespace App; final class Child extends Base {}',
            'src/functions.php' => $functions,
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::YAGNI(sourcePaths: ['src/']));

        $violations = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule(YagniPreset::EXTENDED_CLASS_MUST_BE_ABSTRACT_OR_INSTANTIATED);

        $this->assertCount(1, $violations);
        $this->assertSame('App\Base', $violations[0]->className);
    }

    public function testExtendedClassMustBeAbstractOrInstantiatedRulePassesOnReflectionInstantiation(): void
    {
        // The chained ReflectionClass constructor argument resolves to
        // App\Base, so the newInstance() call counts as instantiating Base.
        $bootstrap = '<?php' . "\n"
            . '$instance = (new ReflectionClass(App\Base::class))->newInstance();';

        $basePath = $this->makeTempProject([
            'src/Base.php'      => '<?php namespace App; class Base {}',
            'src/Child.php'     => '<?php namespace App; final class Child extends Base {}',
            'src/bootstrap.php' => $bootstrap,
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::YAGNI(sourcePaths: ['src/']));

        $violations = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule(YagniPreset::EXTENDED_CLASS_MUST_BE_ABSTRACT_OR_INSTANTIATED);

        $this->assertCount(0, $violations);
    }

    public function testExtendedClassMustBeAbstractOrInstantiatedRuleStillFlagsWithUnresolvableReflection(): void
    {
        // Reflection over a runtime-named class is outside the scanned-code
        // boundary and must not silence the rule for every class.
        $bootstrap = '<?php' . "\n"
            . '$reflection = new ReflectionClass((string) $_ENV[\'CLASS\']);' . "\n"
            . '$instance = $reflection->newInstance();';

        $basePath = $this->makeTempProject([
            'src/Base.php'      => '<?php namespace App; class Base {}',
            'src/Child.php'     => '<?php namespace App; final class Child extends Base {}',
            'src/bootstrap.php' => $bootstrap,
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::YAGNI(sourcePaths: ['src/']));

        $violations = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule(YagniPreset::EXTENDED_CLASS_MUST_BE_ABSTRACT_OR_INSTANTIATED);

        $this->assertCount(1, $violations);
        $this->assertSame('App\Base', $violations[0]->className);
    }

    public function testExtendedClassMustBeAbstractOrInstantiatedRulePassesOnSelfAndParentInstantiation(): void
    {
        // `new self()` resolves to the class itself even when called through a
        // subclass, and `new parent()` resolves to the extended class — both
        // would fatal if the target became abstract.
        $connection = '<?php namespace App;' . "\n"
            . 'class Connection { public static function open(): self { return new self(); } }';
        $pool       = '<?php namespace App;' . "\n"
            . 'class ConnectionPool extends Connection {' . "\n"
            . '    public function fresh(): Connection { return new parent(); }' . "\n"
            . '}';
        $tuned      = '<?php namespace App;' . "\n"
            . 'final class TunedPool extends ConnectionPool {}';

        $basePath = $this->makeTempProject([
            'src/Connection.php'     => $connection,
            'src/ConnectionPool.php' => $pool,
            'src/TunedPool.php'      => $tuned,
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::YAGNI(sourcePaths: ['src/']));

        $violations = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule(YagniPreset::EXTENDED_CLASS_MUST_BE_ABSTRACT_OR_INSTANTIATED);

        // Connection is protected by both `new self()` and `new parent()`;
        // ConnectionPool is extended but never referenced, so only it is
        // reported.
        $this->assertCount(1, $violations);
        $this->assertSame('App\ConnectionPool', $violations[0]->className);
    }

    public function testYagniRulesDoNotFlagAbstractionsReferencedByClassNameString(): void
    {
        $checker = '<?php namespace App;' . "\n"
            . 'final class Checker {' . "\n"
            . '    public function check(object $obj): bool {'
            . ' $contract = \'App\\Contract\'; return $obj instanceof $contract; }' . "\n"
            . '    public function handlerClass(): string { return \'App\\AbstractHandler\'; }' . "\n"
            . '}';

        $basePath = $this->makeTempProject([
            'src/Contract.php'        => '<?php namespace App; interface Contract {}',
            'src/AbstractHandler.php' => '<?php namespace App; abstract class AbstractHandler {}',
            'src/Helper.php'          => '<?php namespace App; trait Helper {}',
            'src/Checker.php'         => $checker,
            'src/bootstrap.php'       => '<?php $helper = \'App\\Helper\'; return $helper;',
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::YAGNI(sourcePaths: ['src/']));

        $ruleViolationCollection = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential());

        // A class-name string value can reach `new $class` or `instanceof
        // $class` at runtime, so it counts as a reference — in class bodies
        // and procedural code alike.
        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testYagniRulesDoNotFlagAbstractionsReferencedByProceduralCode(): void
    {
        $functions = '<?php namespace App;' . "\n"
            . 'function handle(Contract $contract): void {}' . "\n"
            . 'function make(): AbstractHandler { return new class extends AbstractHandler {}; }' . "\n"
            . 'function helperName(): string { return Helper::class; }';

        $basePath = $this->makeTempProject([
            'src/Contract.php'        => '<?php namespace App; interface Contract {}',
            'src/AbstractHandler.php' => '<?php namespace App; abstract class AbstractHandler {}',
            'src/Helper.php'          => '<?php namespace App; trait Helper {}',
            'src/functions.php'       => $functions,
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::YAGNI(sourcePaths: ['src/']));

        $ruleViolationCollection = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential());

        // Type hints and ::class references in procedural code have no
        // ClassNode of their own but still keep the abstractions alive.
        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testYagniRulesDoNotFlagAbstractionsReferencedByTopLevelAnonymousClassBody(): void
    {
        // Migration-style file: no named class at all; the reference lives in
        // the anonymous class body, not in its extends/implements/use clauses.
        $registration = '<?php use App\Contract;' . "\n"
            . 'return new class {' . "\n"
            . '    public function check(object $value): bool { return $value instanceof Contract; }' . "\n"
            . '};';

        $basePath = $this->makeTempProject([
            'src/Contract.php'     => '<?php namespace App; interface Contract {}',
            'src/registration.php' => $registration,
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::YAGNI(sourcePaths: ['src/']));

        $ruleViolationCollection = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential());

        $this->assertCount(0, $ruleViolationCollection->forRule(YagniPreset::INTERFACE_MUST_BE_USED));
    }

    public function testYagniRulesRecognizeProceduralReferencesOnCachedRun(): void
    {
        $functions = '<?php namespace App;' . "\n"
            . 'function handle(Contract $contract): void {}' . "\n"
            . 'function makeBase(): PlainBase { return new PlainBase(); }';

        $basePath            = $this->makeTempProject([
            'src/Contract.php'  => '<?php namespace App; interface Contract {}',
            'src/PlainBase.php' => '<?php namespace App; class PlainBase {}',
            'src/PlainSub.php'  => '<?php namespace App; final class PlainSub extends PlainBase {}',
            'src/functions.php' => $functions,
        ]);
        $analysisResultCache = new AnalysisResultCache($basePath, new FileHashProvider(), 'cache');

        $architecture = Architecture::define()
            ->withPreset(Preset::YAGNI(sourcePaths: ['src/']));

        $ruleViolationCollection = (new Analyser($basePath, $analysisResultCache, 'config'))
            ->analyse($architecture, [], null, AnalyserOptions::sequential());
        $warmViolationCollection = (new Analyser($basePath, $analysisResultCache, 'config'))
            ->analyse($architecture, [], null, AnalyserOptions::sequential());

        // Procedural references must survive the class-node cache round-trip.
        $this->assertFalse($ruleViolationCollection->hasViolations());
        $this->assertFalse($warmViolationCollection->hasViolations());
    }

    public function testYagniRulesRecognizeProceduralReferencesOnCachedRunWithFileAnalysis(): void
    {
        $functions = '<?php namespace App;' . "\n"
            . 'function handle(Contract $contract): void {}' . "\n"
            . 'function makeBase(): PlainBase { return new PlainBase(); }';

        $basePath            = $this->makeTempProject([
            'src/Contract.php'  => '<?php namespace App; interface Contract {}',
            'src/PlainBase.php' => '<?php namespace App; class PlainBase {}',
            'src/PlainSub.php'  => '<?php namespace App; final class PlainSub extends PlainBase {}',
            'src/functions.php' => $functions,
        ]);
        $analysisResultCache = new AnalysisResultCache($basePath, new FileHashProvider(), 'cache');

        // A file-analysis rule makes the warm run load class nodes through the
        // file-analysis cache path, which must also restore file references.
        $architecture = Architecture::define()
            ->withPreset(Preset::YAGNI(sourcePaths: ['src/']))
            ->rule('psr1.php_tags', new Psr1PhpTagsRule(['src/']));

        $ruleViolationCollection = (new Analyser($basePath, $analysisResultCache, 'config'))
            ->analyse($architecture, [], null, AnalyserOptions::sequential());
        $warmViolationCollection = (new Analyser($basePath, $analysisResultCache, 'config'))
            ->analyse($architecture, [], null, AnalyserOptions::sequential());

        $this->assertFalse($ruleViolationCollection->hasViolations());
        $this->assertFalse($warmViolationCollection->hasViolations());
    }

    public function testYagniRulesDoNotFlagAbstractionsUsedByAnonymousClass(): void
    {
        $factory = '<?php namespace App;' . "\n"
            . 'final class Factory {' . "\n"
            . '    public function make(): Contract { return new class implements Contract { use Helper; }; }' . "\n"
            . '}';

        $basePath = $this->makeTempProject([
            'src/Contract.php' => '<?php namespace App; interface Contract {}',
            'src/Helper.php'   => '<?php namespace App; trait Helper {}',
            'src/Factory.php'  => $factory,
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::YAGNI(sourcePaths: ['src/']));

        $ruleViolationCollection = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential());

        $this->assertCount(0, $ruleViolationCollection->forRule(YagniPreset::INTERFACE_MUST_BE_USED));
        $this->assertCount(0, $ruleViolationCollection->forRule(YagniPreset::TRAIT_MUST_BE_USED));
    }

    public function testYagniRulesDoNotFlagAnonymousClassUsageOnCachedRun(): void
    {
        $factory = '<?php namespace App;' . "\n"
            . 'final class Factory {' . "\n"
            . '    public function make(): Contract { return new class implements Contract { use Helper; }; }' . "\n"
            . '}';

        $basePath            = $this->makeTempProject([
            'src/Contract.php' => '<?php namespace App; interface Contract {}',
            'src/Helper.php'   => '<?php namespace App; trait Helper {}',
            'src/Factory.php'  => $factory,
        ]);
        $analysisResultCache = new AnalysisResultCache($basePath, new FileHashProvider(), 'cache');

        $architecture = Architecture::define()
            ->withPreset(Preset::YAGNI(sourcePaths: ['src/']));

        $ruleViolationCollection = (new Analyser($basePath, $analysisResultCache, 'config'))
            ->analyse($architecture, [], null, AnalyserOptions::sequential());
        $warmViolationCollection = (new Analyser($basePath, $analysisResultCache, 'config'))
            ->analyse($architecture, [], null, AnalyserOptions::sequential());

        // The anonymous-class implements/use lists must survive the class-node
        // cache round-trip.
        $this->assertFalse($ruleViolationCollection->hasViolations());
        $this->assertFalse($warmViolationCollection->hasViolations());
    }

    public function testYagniRulesFollowOriginalNamesWhenUsageIsAliased(): void
    {
        $consumer = '<?php namespace App\Sub;' . "\n"
            . 'use App\BaseHandler as BaseAlias;' . "\n"
            . 'use App\Contract as ContractAlias;' . "\n"
            . 'use App\Helper as HelperAlias;' . "\n"
            . 'final class Consumer extends BaseAlias implements ContractAlias { use HelperAlias; }';

        $basePath = $this->makeTempProject([
            'src/BaseHandler.php'  => '<?php namespace App; abstract class BaseHandler {}',
            'src/Contract.php'     => '<?php namespace App; interface Contract {}',
            'src/Helper.php'       => '<?php namespace App; trait Helper {}',
            'src/Sub/Consumer.php' => $consumer,
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::YAGNI(sourcePaths: ['src/']));

        $ruleViolationCollection = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential());

        // Aliased imports resolve to the original names, so the abstractions
        // count as used.
        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testYagniRulesRecognizeUsageWithParallelRunner(): void
    {
        $consumer = '<?php namespace App;' . "\n"
            . 'final class Consumer extends UsedBase implements UsedInterface { use UsedTrait; }';

        $basePath = $this->makeTempProject([
            'src/UsedInterface.php' => '<?php namespace App; interface UsedInterface {}',
            'src/UsedBase.php'      => '<?php namespace App; abstract class UsedBase {}',
            'src/UsedTrait.php'     => '<?php namespace App; trait UsedTrait {}',
            'src/Contract.php'      => '<?php namespace App; interface Contract {}',
            'src/PlainBase.php'     => '<?php namespace App; class PlainBase {}',
            'src/PlainSub.php'      => '<?php namespace App; final class PlainSub extends PlainBase {}',
            'src/Consumer.php'      => $consumer,
            'src/functions.php'     => '<?php namespace App;'
                . ' function handle(Contract $contract): void {}'
                . ' function makeBase(): PlainBase { return new PlainBase(); }',
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::YAGNI(sourcePaths: ['src/']));

        $ruleViolationCollection = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::parallel());

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testMustBeFinalRuleReportsSameExtendedClassesOnCachedRun(): void
    {
        $basePath            = $this->makeTempProject([
            'src/BaseHandler.php'    => '<?php namespace App; class BaseHandler {}',
            'src/OrderHandler.php'   => '<?php namespace App; final class OrderHandler extends BaseHandler {}',
            'src/PaymentHandler.php' => '<?php namespace App; class PaymentHandler {}',
        ]);
        $analysisResultCache = new AnalysisResultCache($basePath, new FileHashProvider(), 'cache');

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $coldViolations = (new Analyser($basePath, $analysisResultCache, 'config'))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule('source.must_be_final');
        $warmViolations = (new Analyser($basePath, $analysisResultCache, 'config'))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule('source.must_be_final');

        $this->assertCount(1, $coldViolations);
        $this->assertSame('App\PaymentHandler', $coldViolations[0]->className);
        $this->assertCount(1, $warmViolations);
        $this->assertSame('App\PaymentHandler', $warmViolations[0]->className);
    }

    public function testMustBeFinalRuleFlagsCachedClassOnceItsExtendingClassIsRemoved(): void
    {
        $basePath            = $this->makeTempProject([
            'src/BaseHandler.php'  => '<?php namespace App; class BaseHandler {}',
            'src/OrderHandler.php' => '<?php namespace App; final class OrderHandler extends BaseHandler {}',
        ]);
        $analysisResultCache = new AnalysisResultCache($basePath, new FileHashProvider(), 'cache');

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $coldViolations = (new Analyser($basePath, $analysisResultCache, 'config'))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule('source.must_be_final');

        $this->assertCount(0, $coldViolations);

        // BaseHandler.php is untouched, so its class-node cache entry stays valid;
        // the extended flag must still be recomputed against the shrunken class set.
        unlink($basePath . '/src/OrderHandler.php');

        $warmViolations = (new Analyser($basePath, $analysisResultCache, 'config'))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule('source.must_be_final');

        $this->assertCount(1, $warmViolations);
        $this->assertSame('App\BaseHandler', $warmViolations[0]->className);
    }

    public function testAnalyserMarksFixableRuleViolations(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php' => '<?php namespace App; class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential());

        $violations = $ruleViolationCollection->forRule('source.must_be_final');

        $this->assertCount(1, $violations);
        $this->assertTrue($violations[0]->fixable);
    }

    public function testAnalyserCollectsClassNodesWithSequentialRunnerAndLayerPatterns(): void
    {
        $basePath = $this->makeTempProject([
            'src/HTTP/Request.php' => '<?php namespace App\HTTP; class Request {}',
        ]);

        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->rule('http.must_be_final', new MustBeFinalRule('HTTP'));

        $ruleViolationCollection = (new Analyser($basePath))
            ->analyse($architecture, ['src/'], null, AnalyserOptions::sequential());

        $this->assertCount(1, $ruleViolationCollection->forRule('http.must_be_final'));
    }

    public function testAnalyserCollectsClassNodesWithDefaultParallelRunner(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php' => '<?php namespace App; class Foo {}',
            'src/Bar.php' => '<?php namespace App; class Bar {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertCount(2, $ruleViolationCollection->forRule('source.must_be_final'));
    }

    public function testArchitectureLayerPathDoesNotMatchSiblingDirectoryWithSamePrefix(): void
    {
        $basePath = $this->makeTempProject([
            'src/App/Controller.php'      => '<?php namespace Project\App; class Controller {}',
            'src/Application/UseCase.php' => '<?php namespace Project\Application; class UseCase {}',
        ]);

        $architecture = Architecture::define()
            ->layer('App', 'src/App/')
            ->layer('Application', 'src/Application/')
            ->rule('app.must_be_final', new MustBeFinalRule('App'));

        $ruleViolationCollection = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential());

        $violations = $ruleViolationCollection->forRule('app.must_be_final');

        $this->assertCount(1, $violations);
        $this->assertStringEndsWith('/src/App/Controller.php', $this->normalisePath($violations[0]->file));
    }

    public function testArchitectureLayerCanBeConfiguredForSingleFilePath(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php' => '<?php namespace App; class Foo {}',
            'src/Bar.php' => '<?php namespace App; class Bar {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/Foo.php')
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential());

        $violations = $ruleViolationCollection->forRule('source.must_be_final');

        $this->assertCount(1, $violations);
        $this->assertStringEndsWith('/src/Foo.php', $this->normalisePath($violations[0]->file));
    }

    public function testAnalyserReturnsEmptyCollectionForEmptyLayers(): void
    {
        $architecture = Architecture::define()
            ->layer('Domain', 'tests/Fixtures/sample/src/Domain/Events/');

        $analyser                = new Analyser(dirname(__DIR__, 2));
        $ruleViolationCollection = $analyser->analyse($architecture);

        // Events directory is empty — no violations
        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserSkipsNonExistentPaths(): void
    {
        $architecture = Architecture::define()
            ->layer('Domain', 'tests/Fixtures/sample/src/DoesNotExist/');

        $analyser = new Analyser(dirname(__DIR__, 2));

        // Should not throw — simply skip missing directories
        $ruleViolationCollection = $analyser->analyse($architecture);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserSkipsFilesWithParseErrors(): void
    {
        $basePath = $this->makeTempProject([
            'src/Domain/Broken.php' => '<?php class Broken {',
        ]);

        $architecture = Architecture::define()
            ->layer('Domain', 'src/Domain/');

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserSkipsFilesWithEmptyAst(): void
    {
        $basePath = $this->makeTempProject([
            'src/Domain/Empty.php' => '<?php',
        ]);

        $architecture = Architecture::define()
            ->layer('Domain', 'src/Domain/');

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserEvaluatesProjectRules(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
            'src/Foo.php'   => '<?php namespace App; final class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::PSR4(sourcePaths: ['src/', 'tests/']));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertTrue($ruleViolationCollection->hasViolations());
        $this->assertCount(1, $ruleViolationCollection->forRule('psr4.source_paths.must_be_in_composer'));
    }

    public function testAnalyserSkipsComposerProjectRuleWithExplicitComposerJsonRuleSkip(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
            'src/Foo.php'   => '<?php namespace App; final class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::PSR4(sourcePaths: ['src/', 'tests/']))
            ->skip([
                Psr4Preset::SOURCE_PATHS_MUST_BE_IN_COMPOSER => ['composer.json'],
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertCount(0, $ruleViolationCollection->forRule(Psr4Preset::SOURCE_PATHS_MUST_BE_IN_COMPOSER));
    }

    public function testAnalyserSkipsComposerProjectRuleWithGlobalComposerJsonSkip(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
            'src/Foo.php'   => '<?php namespace App; final class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::PSR4(sourcePaths: ['src/', 'tests/']))
            ->skip(['composer.json']);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertCount(0, $ruleViolationCollection->forRule(Psr4Preset::SOURCE_PATHS_MUST_BE_IN_COMPOSER));
    }

    public function testAnalyserSkipPathOnProjectRuleSuppressesViolations(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json'     => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
            'src/Foo.php'       => "<?php\nini_set('memory_limit', '1G');\nclass Foo {}\n",
            'tests/FooTest.php' => "<?php\nini_set('memory_limit', '1G');\nclass FooTest {}\n",
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::PSR1(sourcePaths: ['src/', 'tests/']))
            ->skip([
                Psr1Preset::FILES_SHOULD_DECLARE_SYMBOLS_OR_SIDE_EFFECTS => [$basePath . '/tests'],
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $violations = $ruleViolationCollection->forRule(Psr1Preset::FILES_SHOULD_DECLARE_SYMBOLS_OR_SIDE_EFFECTS);
        $this->assertCount(1, $violations);
        $this->assertStringEndsWith('/src/Foo.php', $this->normalisePath($violations[0]->file));
    }

    public function testAnalyserAppliesGlobalSkipPathsToFileProjectRules(): void
    {
        $basePath = $this->makeTempProject([
            'src/Fixtures/Bad.php' => '<? echo "x";',
        ]);

        $architecture = Architecture::define()
            ->skip(['src/Fixtures/'])
            ->rule('psr1.php_tags', new Psr1PhpTagsRule(['src/']));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertCount(0, $ruleViolationCollection->forRule('psr1.php_tags'));
    }

    public function testAnalyserScopesFileProjectRulesToExplicitScanPaths(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json'       => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
            'src/Alpha/Alpha.php' => '<? echo "alpha";',
            'src/Beta/Beta.php'   => '<? echo "beta";',
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::PSR1());

        $violations = (new Analyser($basePath))
            ->analyse($architecture, ['src/Alpha'], analyserOptions: AnalyserOptions::sequential())
            ->forRule(Psr1Preset::FILES_MUST_USE_VALID_TAGS);

        $this->assertCount(1, $violations);
        $this->assertStringEndsWith('/src/Alpha/Alpha.php', $this->normalisePath($violations[0]->file));
    }

    public function testAnalyserPreservesAnExplicitEmptyFileScope(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json'       => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
            'src/Empty/readme.md' => 'No PHP files here.',
            'src/Beta/Beta.php'   => '<? echo "beta";',
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::PSR1());

        $violations = (new Analyser($basePath))
            ->analyse($architecture, ['src/Empty'], analyserOptions: AnalyserOptions::sequential())
            ->forRule(Psr1Preset::FILES_MUST_USE_VALID_TAGS);

        $this->assertSame([], $violations);
    }

    public function testAnalyserScopesFileProjectRulesToNarrowerLayerPaths(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json'       => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
            'src/Alpha/Alpha.php' => '<? echo "alpha";',
            'src/Beta/Beta.php'   => '<? echo "beta";',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/Alpha/')
            ->withPreset(Preset::PSR1());

        $violations = (new Analyser($basePath))
            ->analyse($architecture, analyserOptions: AnalyserOptions::sequential())
            ->forRule(Psr1Preset::FILES_MUST_USE_VALID_TAGS);

        $this->assertCount(1, $violations);
        $this->assertStringEndsWith('/src/Alpha/Alpha.php', $this->normalisePath($violations[0]->file));
    }

    public function testAnalyserPsr1RuleFindsViolationsWithAbsoluteSourcePath(): void
    {
        $srcPath = $this->makeTempProject([
            'Foo.php' => "<?php\nini_set('memory_limit', '1G');\nclass Foo {}\n",
        ]);

        $unrelatedBase = $this->makeTemporaryDirectory('structarmed-unrelated');

        $architecture = Architecture::define()
            ->withPreset(Preset::PSR1(sourcePaths: [$srcPath]));

        $ruleViolationCollection = (new Analyser($unrelatedBase))->analyse($architecture);

        $violations = $ruleViolationCollection->forRule(Psr1Preset::FILES_SHOULD_DECLARE_SYMBOLS_OR_SIDE_EFFECTS);
        $this->assertCount(1, $violations);
        $this->assertStringEndsWith('/Foo.php', $this->normalisePath($violations[0]->file));
    }

    public function testAnalyserContinuesWhenProjectRulePasses(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
            'src/Foo.php'   => '<?php namespace App; final class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::PSR4(sourcePaths: ['src/']));

        $analyser = new Analyser($basePath);
        $files    = array_map($this->normalisePath(...), $analyser->filesForAnalysis($architecture));

        sort($files);

        $this->assertCount(2, $files);
        $this->assertStringEndsWith('/composer.json', $files[0]);
        $this->assertStringEndsWith('/src/Foo.php', $files[1]);

        $ruleViolationCollection = $analyser->analyse($architecture);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testPsr1AndPsr4PreserveBothSourceScopesRegardlessOfPresetOrder(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json'        => '{"autoload":{"psr-4":{"App\\\\":"src/"}},'
                . '"autoload-dev":{"psr-4":{"Tests\\\\":"tests/"}}}',
            'src/Invalid.php'      => '<?php namespace Wrong; final class Invalid {}',
            'src/InvalidTag.php'   => '<? echo "src";',
            'tests/InvalidTag.php' => '<? echo "tests";',
        ]);

        $architectures = [
            Architecture::define()
                ->withPreset(Preset::PSR4(sourcePaths: ['src/']))
                ->withPreset(Preset::PSR1(sourcePaths: ['tests/'])),
            Architecture::define()
                ->withPreset(Preset::PSR1(sourcePaths: ['tests/']))
                ->withPreset(Preset::PSR4(sourcePaths: ['src/'])),
        ];

        foreach ($architectures as $architecture) {
            $result = (new Analyser($basePath))
                ->analyse($architecture, analyserOptions: AnalyserOptions::sequential());

            $violations = $result->forRule(Psr4Preset::CLASSES_MUST_MATCH_COMPOSER);

            $this->assertCount(1, $violations);
            $this->assertStringEndsWith('/src/Invalid.php', $this->normalisePath($violations[0]->file));

            $tagViolations = $result->forRule(Psr1Preset::FILES_MUST_USE_VALID_TAGS);

            $this->assertCount(1, $tagViolations);
            $this->assertStringEndsWith('/tests/InvalidTag.php', $this->normalisePath($tagViolations[0]->file));
            $this->assertStringNotContainsString('/src/', $this->normalisePath($tagViolations[0]->file));
        }
    }

    public function testPsr4Psr1AndPsr12PreserveInheritedSourceScopesRegardlessOfPresetOrder(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json'        => '{"autoload":{"psr-4":{"Psr4\\\\":"psr4/",'
                . '"Psr1\\\\":"psr1/","Psr12\\\\":"psr12/"}}}',
            'psr4/Invalid.php'     => '<?php namespace Wrong; final class Psr4Invalid {'
                . ' public const ENABLED = TRUE; function missingVisibility() {} }',
            'psr4/InvalidTag.php'  => '<? echo "psr4";',
            'psr1/Invalid.php'     => '<?php namespace Wrong; final class Psr1Invalid {'
                . ' public const ENABLED = TRUE; function missingVisibility() {} }',
            'psr1/InvalidTag.php'  => '<? echo "psr1";',
            'psr12/Invalid.php'    => '<?php namespace Wrong; final class Psr12Invalid {'
                . ' public const ENABLED = TRUE; function missingVisibility() {} }',
            'psr12/InvalidTag.php' => '<? echo "psr12";',
        ]);

        $architectures = [
            Architecture::define()
                ->withPreset(Preset::PSR4(sourcePaths: ['psr4/']))
                ->withPreset(Preset::PSR1(sourcePaths: ['psr1/']))
                ->withPreset(Preset::PSR12(sourcePaths: ['psr12/'])),
            Architecture::define()
                ->withPreset(Preset::PSR4(sourcePaths: ['psr4/']))
                ->withPreset(Preset::PSR12(sourcePaths: ['psr12/']))
                ->withPreset(Preset::PSR1(sourcePaths: ['psr1/'])),
            Architecture::define()
                ->withPreset(Preset::PSR1(sourcePaths: ['psr1/']))
                ->withPreset(Preset::PSR4(sourcePaths: ['psr4/']))
                ->withPreset(Preset::PSR12(sourcePaths: ['psr12/'])),
            Architecture::define()
                ->withPreset(Preset::PSR1(sourcePaths: ['psr1/']))
                ->withPreset(Preset::PSR12(sourcePaths: ['psr12/']))
                ->withPreset(Preset::PSR4(sourcePaths: ['psr4/'])),
            Architecture::define()
                ->withPreset(Preset::PSR12(sourcePaths: ['psr12/']))
                ->withPreset(Preset::PSR4(sourcePaths: ['psr4/']))
                ->withPreset(Preset::PSR1(sourcePaths: ['psr1/'])),
            Architecture::define()
                ->withPreset(Preset::PSR12(sourcePaths: ['psr12/']))
                ->withPreset(Preset::PSR1(sourcePaths: ['psr1/']))
                ->withPreset(Preset::PSR4(sourcePaths: ['psr4/'])),
        ];

        foreach ($architectures as $architecture) {
            $result = (new Analyser($basePath))
                ->analyse($architecture, analyserOptions: AnalyserOptions::sequential());

            $psr4Violations = $result->forRule(Psr4Preset::CLASSES_MUST_MATCH_COMPOSER);
            $this->assertCount(3, $psr4Violations);

            $psr1Violations = $result->forRule(Psr1Preset::FILES_MUST_USE_VALID_TAGS);
            $this->assertCount(2, $psr1Violations);
            $psr1ViolationFiles = array_map(
                fn (RuleViolation $ruleViolation): string => $this->normalisePath($ruleViolation->file),
                $psr1Violations,
            );
            sort($psr1ViolationFiles);
            $this->assertStringEndsWith('/psr1/InvalidTag.php', $psr1ViolationFiles[0]);
            $this->assertStringEndsWith('/psr12/InvalidTag.php', $psr1ViolationFiles[1]);

            $psr12Violations = $result->forRule(Psr12Preset::METHODS_MUST_DECLARE_VISIBILITY);
            $this->assertCount(1, $psr12Violations);
            $this->assertStringEndsWith('/psr12/Invalid.php', $this->normalisePath($psr12Violations[0]->file));

            $keywordConstantViolations = $result->forRule(
                Psr12Preset::FILES_MUST_USE_LOWERCASE_KEYWORD_CONSTANTS
            );
            $this->assertCount(1, $keywordConstantViolations);
            $this->assertStringEndsWith(
                '/psr12/Invalid.php',
                $this->normalisePath($keywordConstantViolations[0]->file)
            );
        }
    }

    public function testFilesForAnalysisIgnoresMissingRootComposerJsonCandidate(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php' => '<?php namespace App; final class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::PSR4(sourcePaths: ['src/']));

        $files = array_map($this->normalisePath(...), (new Analyser($basePath))->filesForAnalysis($architecture));

        $this->assertCount(1, $files);
        $this->assertStringEndsWith('/src/Foo.php', $files[0]);
    }

    public function testFilesForAnalysisResolvesSourceFromComposerPsr4WhenSourceLayerIsNotDefined(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
            'src/Foo.php'   => '<?php namespace App; final class Foo {}',
        ]);

        $architecture = Architecture::define();

        $files = array_map($this->normalisePath(...), (new Analyser($basePath))->filesForAnalysis($architecture));

        $this->assertCount(1, $files);
        $this->assertStringEndsWith('/src/Foo.php', $files[0]);
    }

    public function testAnalyserResolvesSourceFromComposerPsr4WhenSourceLayerIsNotDefined(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"app/"}}}',
            'app/Foo.php'   => '<?php namespace App; class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertCount(1, $ruleViolationCollection->forRule('source.must_be_final'));
    }

    public function testAnalyserReportsSameViolationsWithAndWithoutExplicitScanPaths(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json'          => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
            'src/Domain/Order.php'   => <<<'PHP'
                <?php

                namespace App\Domain;

                use App\Support\Helper;

                final class Order
                {
                    public function __construct(private Helper $helper) {}
                }
                PHP,
            'src/Support/Helper.php' => <<<'PHP'
                <?php

                namespace App\Support;

                class Helper {}
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('Domain', 'src/Domain/')
            ->layer('Infra', 'src/Infra/')
            ->ruleset(['Domain' => ['Infra']])
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $analyser = new Analyser($basePath);

        $bareViolations     = $this->violationTuples($analyser->analyse($architecture));
        $explicitViolations = $this->violationTuples($analyser->analyse($architecture, ['src/']));

        // The synthesised Source layer classifies files identically whether the
        // scan set comes from composer PSR-4 paths or explicit scan paths:
        // the Source-targeted rule fires, the ruleset stays inert.
        $this->assertSame($bareViolations, $explicitViolations);
        $this->assertCount(1, $bareViolations);
        $this->assertSame('source.must_be_final', $bareViolations[0][0]);
        $this->assertSame('App\Support\Helper', $bareViolations[0][1]);
    }

    public function testAnalyserReportsSameViolationsAcrossScanModesWithSharedCache(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json'          => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
            'src/Domain/Order.php'   => <<<'PHP'
                <?php

                namespace App\Domain;

                use App\Support\Helper;

                final class Order
                {
                    public function __construct(private Helper $helper) {}
                }
                PHP,
            'src/Support/Helper.php' => <<<'PHP'
                <?php

                namespace App\Support;

                class Helper {}
                PHP,
        ]);

        $analysisResultCache = new AnalysisResultCache($basePath, new FileHashProvider(), 'cache');

        $architecture = Architecture::define()
            ->layer('Domain', 'src/Domain/')
            ->layer('Infra', 'src/Infra/')
            ->ruleset(['Domain' => ['Infra']])
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        // A result cached under one scan mode must not leak different layer
        // semantics into the other: cold bare, warm explicit, warm bare.
        $bareColdViolations     = $this->violationTuples(
            (new Analyser($basePath, $analysisResultCache, 'config'))
                ->analyse($architecture, [], null, AnalyserOptions::sequential())
        );
        $explicitWarmViolations = $this->violationTuples(
            (new Analyser($basePath, $analysisResultCache, 'config'))
                ->analyse($architecture, ['src/'], null, AnalyserOptions::sequential())
        );
        $bareWarmViolations     = $this->violationTuples(
            (new Analyser($basePath, $analysisResultCache, 'config'))
                ->analyse($architecture, [], null, AnalyserOptions::sequential())
        );

        $this->assertSame($bareColdViolations, $explicitWarmViolations);
        $this->assertSame($bareColdViolations, $bareWarmViolations);
        $this->assertCount(1, $bareColdViolations);
        $this->assertSame('source.must_be_final', $bareColdViolations[0][0]);
        $this->assertSame('App\Support\Helper', $bareColdViolations[0][1]);
    }

    /** @return list<array{string|null, string|null, string}> */
    private function violationTuples(RuleViolationCollection $ruleViolationCollection): array
    {
        $tuples = [];
        foreach ($ruleViolationCollection as $violation) {
            $tuples[] = [$violation->ruleKey, $violation->className, $violation->message];
        }

        sort($tuples);

        return $tuples;
    }

    public function testFilesForAnalysisWidensScanToComposerPsr4PathsWhenSourceLayerIsNotDefined(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json'        => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
            'src/Domain/Order.php' => '<?php namespace App\Domain; final class Order {}',
            'src/Other/Helper.php' => '<?php namespace App\Other; final class Helper {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Domain', 'src/Domain/');

        $files = array_map($this->normalisePath(...), (new Analyser($basePath))->filesForAnalysis($architecture));

        sort($files);

        $this->assertCount(2, $files);
        $this->assertStringEndsWith('/src/Domain/Order.php', $files[0]);
        $this->assertStringEndsWith('/src/Other/Helper.php', $files[1]);
    }

    public function testAnalyserResolvesSourceFromComposerPsr4WithExplicitScanPaths(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"app/"}}}',
            'app/Foo.php'   => '<?php namespace App; class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['app/']);

        $this->assertCount(1, $ruleViolationCollection->forRule('source.must_be_final'));
    }

    public function testSynthesisedSourceLayerIsRulesetInertLikeExplicitEmptySource(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json'          => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
            'src/Domain/Order.php'   => <<<'PHP'
                <?php

                namespace App\Domain;

                use App\Support\Helper;

                final class Order
                {
                    public function __construct(private Helper $helper) {}
                }
                PHP,
            'src/Support/Helper.php' => <<<'PHP'
                <?php

                namespace App\Support;

                final class Helper {}
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('Domain', 'src/Domain/')
            ->layer('Infra', 'src/Infra/')
            ->ruleset(['Domain' => ['Infra']]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testFilesForAnalysisIncludesRootComposerJsonForComposerJsonRule(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
            'src/Foo.php'   => '<?php namespace App; final class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Domain', 'src/')
            ->rule('composer.source_paths', new Psr4SourcePathsRule(['src/']));

        $analyser = new Analyser($basePath);
        $files    = array_map(
            $this->normalisePath(...),
            $analyser->filesForAnalysis($architecture)
        );

        sort($files);

        $this->assertCount(2, $files);
        $this->assertStringEndsWith('/composer.json', $files[0]);
        $this->assertStringEndsWith('/src/Foo.php', $files[1]);
    }

    public function testFilesForAnalysisDoesNotIncludeRootComposerJsonWithoutComposerJsonRule(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
            'src/Foo.php'   => '<?php namespace App; final class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Domain', 'src/');

        $files = array_map($this->normalisePath(...), (new Analyser($basePath))->filesForAnalysis($architecture));

        $this->assertCount(1, $files);
        $this->assertStringEndsWith('/src/Foo.php', $files[0]);
    }

    public function testFilesForAnalysisDoesNotIncludeRootComposerJsonWhenComposerJsonRuleSkipsIt(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
            'src/Foo.php'   => '<?php namespace App; final class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Domain', 'src/')
            ->rule('composer.source_paths', new Psr4SourcePathsRule(['src/']))
            ->skip(['composer.source_paths' => ['composer.json']]);

        $files = array_map($this->normalisePath(...), (new Analyser($basePath))->filesForAnalysis($architecture));

        $this->assertCount(1, $files);
        $this->assertStringEndsWith('/src/Foo.php', $files[0]);
    }

    public function testFilesForAnalysisDoesNotIncludeRootComposerJsonWhenComposerJsonRuleIsSkipped(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
            'src/Foo.php'   => '<?php namespace App; final class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Domain', 'src/')
            ->rule('composer.source_paths', new Psr4SourcePathsRule(['src/']))
            ->skipRule('composer.source_paths');

        $files = array_map($this->normalisePath(...), (new Analyser($basePath))->filesForAnalysis($architecture));

        $this->assertCount(1, $files);
        $this->assertStringEndsWith('/src/Foo.php', $files[0]);
    }

    public function testAnalyserUsesComposerPsr4PathsForDefaultPsr4Preset(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"app/"}}}',
            'app/Foo.php'   => '<?php namespace App; class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::PSR4())
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertCount(1, $ruleViolationCollection->forRule('source.must_be_final'));
    }

    public function testAnalyserUsesComposerPsr4PathsForDefaultPsr15Preset(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"app/"}}}',
            'app/Foo.php'   => '<?php namespace App; class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::PSR15())
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertCount(1, $ruleViolationCollection->forRule('source.must_be_final'));
    }

    public function testPsr15UsesComposerSourcePathsRegardlessOfPresetOrder(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json'      => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
            'src/FooHandler.php' => '<?php namespace App; final class FooHandler {}',
        ]);

        $architectures = [
            Architecture::define()
                ->withPreset(Preset::PSR4(sourcePaths: ['src/']))
                ->withPreset(Preset::PSR15()),
            Architecture::define()
                ->withPreset(Preset::PSR15())
                ->withPreset(Preset::PSR4(sourcePaths: ['src/'])),
        ];

        foreach ($architectures as $architecture) {
            $violations = (new Analyser($basePath))
                ->analyse($architecture, analyserOptions: AnalyserOptions::sequential())
                ->forRule(Psr15Preset::HANDLER_MUST_IMPLEMENT_REQUEST_HANDLER_INTERFACE);

            $this->assertCount(1, $violations);
        }
    }

    public function testEmptySourceArrayLayerUsesExistingSourcePaths(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php' => '<?php namespace App; class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', ['src/'])
            ->layer('Source[]', [])
            ->rule('source_array.must_be_final', new MustBeFinalRule('Source[]'));

        $violations = (new Analyser($basePath))
            ->analyse($architecture, analyserOptions: AnalyserOptions::sequential())
            ->forRule('source_array.must_be_final');

        $this->assertCount(1, $violations);
        $this->assertStringEndsWith('/src/Foo.php', $this->normalisePath($violations[0]->file));
    }

    public function testPsr15PresetAcceptsInterfaceExtendingMiddlewareInterfaceWithMiddlewareSuffix(): void
    {
        $basePath = $this->makeTempProject([
            'app/AuthMiddleware.php' => <<<'PHP'
                <?php

                namespace App;

                interface AuthMiddleware extends \Psr\Http\Server\MiddlewareInterface
                {
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::PSR15(sourcePaths: ['app/']));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);
        $ruleKey                 = Psr15Preset::MIDDLEWARE_INTERFACE_IMPLEMENTATION_MUST_HAVE_MIDDLEWARE_SUFFIX;

        $this->assertCount(
            0,
            $ruleViolationCollection->forRule($ruleKey)
        );
    }

    public function testPsr15PresetReportsClassImplementingCustomMiddlewareInterfaceWithoutMiddlewareSuffix(): void
    {
        $basePath = $this->makeTempProject([
            'app/AuthMiddleware.php' => <<<'PHP'
                <?php

                namespace App;

                interface AuthMiddleware extends \Psr\Http\Server\MiddlewareInterface
                {
                }
                PHP,
            'app/Auth.php'           => <<<'PHP'
                <?php

                namespace App;

                final class Auth implements AuthMiddleware
                {
                    public function process(
                        \Psr\Http\Message\ServerRequestInterface $request,
                        \Psr\Http\Server\RequestHandlerInterface $handler
                    ): \Psr\Http\Message\ResponseInterface {
                        return $handler->handle($request);
                    }
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::PSR15(sourcePaths: ['app/']));

        $violations = (new Analyser($basePath))->analyse($architecture)
            ->forRule(Psr15Preset::MIDDLEWARE_INTERFACE_IMPLEMENTATION_MUST_HAVE_MIDDLEWARE_SUFFIX);

        $this->assertCount(1, $violations);
        $this->assertSame('App\Auth', $violations[0]->className);
    }

    public function testPsr15PresetReportsClassExtendingCustomMiddlewareImplementationWithoutMiddlewareSuffix(): void
    {
        $basePath = $this->makeTempProject([
            'app/AuthMiddleware.php'     => <<<'PHP'
                <?php

                namespace App;

                interface AuthMiddleware extends \Psr\Http\Server\MiddlewareInterface
                {
                }
                PHP,
            'app/BaseAuthMiddleware.php' => <<<'PHP'
                <?php

                namespace App;

                class BaseAuthMiddleware implements AuthMiddleware
                {
                    public function process(
                        \Psr\Http\Message\ServerRequestInterface $request,
                        \Psr\Http\Server\RequestHandlerInterface $handler
                    ): \Psr\Http\Message\ResponseInterface {
                        return $handler->handle($request);
                    }
                }
                PHP,
            'app/Auth.php'               => <<<'PHP'
                <?php

                namespace App;

                final class Auth extends BaseAuthMiddleware
                {
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::PSR15(sourcePaths: ['app/']));

        $violations = (new Analyser($basePath))->analyse($architecture)
            ->forRule(Psr15Preset::MIDDLEWARE_INTERFACE_IMPLEMENTATION_MUST_HAVE_MIDDLEWARE_SUFFIX);

        $this->assertCount(1, $violations);
        $this->assertSame('App\\Auth', $violations[0]->className);
    }

    public function testDddPresetResolvesConventionalLayersInsidePsr4SourceLayer(): void
    {
        $repositoryPath = 'src/Infrastructure/Persistence/Album/SQLAlbumRepository.php';
        $basePath       = $this->makeTempProject([
            'composer.json' => '{"autoload":{"psr-4":{"Album\\\\":"src/"}}}',
            $repositoryPath => <<<'PHP'
                <?php

                namespace Album\Infrastructure\Persistence\Album;

                final class SQLAlbumRepository
                {
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->withPresets(Preset::PSR4(), Preset::DDD());

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertCount(
            0,
            $ruleViolationCollection->forRule('ddd.repository.implementation_in_infrastructure')
        );
    }

    public function testMvcPresetReportsViolationsAcrossConventionalDefaultLayerPaths(): void
    {
        $basePath = $this->makeTempProject([
            'src/Controller/Dashboard.php'  => <<<'PHP'
                <?php

                namespace App\Controller;

                final class Dashboard
                {
                }
                PHP,
            'src/Controllers/Account.php'   => '<?php namespace App\Controllers; final class Account {}',
            'app/Controllers/Report.php'    => '<?php namespace App\Controllers; final class Report {}',
            'app/Http/Controllers/Home.php' => <<<'PHP'
                <?php

                namespace App\Http\Controllers;

                final class Home
                {
                }
                PHP,
            'src/Models/ModelOrder.php'     => '<?php namespace App\Models; final class ModelOrder {}',
            'app/Models/ModelUser.php'      => '<?php namespace App\Models; final class ModelUser {}',
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::MVC());

        $ruleViolationCollection = (new Analyser($basePath))
            ->analyse($architecture, analyserOptions: AnalyserOptions::sequential());

        $controllerClassNames = array_map(
            static fn(RuleViolation $ruleViolation): string => $ruleViolation->className,
            $ruleViolationCollection->forRule(MvcPreset::CONTROLLER_NAME_MUST_END_WITH_CONTROLLER)
        );
        $modelClassNames      = array_map(
            static fn(RuleViolation $ruleViolation): string => $ruleViolation->className,
            $ruleViolationCollection->forRule(MvcPreset::MODEL_NAME_MUST_NOT_START_WITH_MODEL)
        );
        sort($controllerClassNames);
        sort($modelClassNames);

        $this->assertSame([
            'App\\Controller\\Dashboard',
            'App\\Controllers\\Account',
            'App\\Controllers\\Report',
            'App\\Http\\Controllers\\Home',
        ], $controllerClassNames);
        $this->assertSame([
            'App\\Models\\ModelOrder',
            'App\\Models\\ModelUser',
        ], $modelClassNames);
    }

    public function testMvcPresetLayerPatternsClassifyModularNamespacesWithinSourceScope(): void
    {
        $basePath = $this->makeTempProject([
            'module/Blog/Controller/Post.php'      => '<?php namespace Module\Blog\Controller; final class Post {}',
            'module/Blog/Controller/PostTest.php'  => <<<'PHP'
                <?php

                namespace Module\Blog\Controller;

                final class PostTest
                {
                }
                PHP,
            'module/Blog/Model/ModelPost.php'      => '<?php namespace Module\Blog\Model; final class ModelPost {}',
            'module/Blog/View/Page.php'            => <<<'PHP'
                <?php

                namespace Module\Blog\View;

                final class Page
                {
                    public function render(): string
                    {
                        return $_GET['page'];
                    }
                }
                PHP,
            'module/Blog/Service/OrderService.php' => <<<'PHP'
                <?php

                namespace Module\Blog\Service;

                class OrderService
                {
                }
                PHP,
            'tests/AlbumTest/Controller/Fake.php'  => <<<'PHP'
                <?php

                namespace AlbumTest\Controller;

                final class Fake
                {
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('Source', ['module/', 'tests/'])
            ->withPreset(Preset::MVC());

        $ruleViolationCollection = (new Analyser($basePath))
            ->analyse($architecture, analyserOptions: AnalyserOptions::sequential());

        $this->assertCount(
            1,
            $ruleViolationCollection->forRule(MvcPreset::CONTROLLER_NAME_MUST_END_WITH_CONTROLLER)
        );
        $this->assertCount(
            1,
            $ruleViolationCollection->forRule(MvcPreset::MODEL_NAME_MUST_NOT_START_WITH_MODEL)
        );
        $this->assertCount(1, $ruleViolationCollection->forRule(MvcPreset::VIEW_NO_SUPERGLOBALS));
        $this->assertCount(1, $ruleViolationCollection->forRule(MvcPreset::SERVICE_MUST_BE_FINAL));
    }

    public function testAnalyserCanLimitScanToSpecificFile(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php' => '<?php namespace App; class Foo {}',
            'src/Bar.php' => '<?php namespace App; class Bar {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', ['src/'])
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/Foo.php']);

        $this->assertCount(1, $ruleViolationCollection->forRule('source.must_be_final'));
        $this->assertStringEndsWith(
            '/src/Foo.php',
            $this->normalisePath($ruleViolationCollection->forRule('source.must_be_final')[0]->file)
        );
    }

    public function testAnalyserCanLimitScanToSpecificPaths(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php'   => '<?php namespace App; class Foo {}',
            'tests/Bar.php' => '<?php namespace App\Tests; class Bar {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', ['src/', 'tests/'])
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertCount(1, $ruleViolationCollection->forRule('source.must_be_final'));
        $this->assertStringEndsWith(
            '/src/Foo.php',
            $this->normalisePath($ruleViolationCollection->forRule('source.must_be_final')[0]->file)
        );
    }

    public function testAnalyserDoesNotScanDuplicateLayerPathsTwice(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php' => '<?php namespace App; class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', ['src/', 'src/'])
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertCount(1, $ruleViolationCollection->forRule('source.must_be_final'));
    }

    public function testAnalyserReportsProgressForScannedPhpFiles(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php'   => '<?php namespace App; final class Foo {}',
            'src/Bar.php'   => '<?php namespace App; final class Bar {}',
            'src/readme.md' => '# ignored',
        ]);
        $progress = new class implements ProgressHandlerInterface {
            public int $total = 0;

            /** @var list<string> */
            public array $files = [];

            public bool $finished = false;

            public function start(int $total): void
            {
                $this->total = $total;
            }

            public function advance(string $file): void
            {
                $this->files[] = $file;
            }

            public function finish(): void
            {
                $this->finished = true;
            }
        };

        $architecture = Architecture::define()
            ->layer('Source', 'src/');

        (new Analyser($basePath))->analyse($architecture, [], $progress);

        $this->assertSame(2, $progress->total);
        $this->assertCount(2, $progress->files);
        $this->assertTrue($progress->finished);
    }

    public function testAnalyserReportsProgressFromParallelWorkers(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php' => '<?php namespace App; final class Foo {}',
            'src/Bar.php' => '<?php namespace App; final class Bar {}',
        ]);
        $progress = new class implements ProgressHandlerInterface {
            public int $total = 0;

            /** @var list<string> */
            public array $files = [];

            public bool $finished = false;

            public function start(int $total): void
            {
                $this->total = $total;
            }

            public function advance(string $file): void
            {
                $this->files[] = $file;
            }

            public function finish(): void
            {
                $this->finished = true;
            }
        };

        $architecture = Architecture::define()
            ->layer('Source', 'src/');

        (new Analyser($basePath))->analyse(
            $architecture,
            [],
            $progress,
            AnalyserOptions::parallel(2)
        );

        $fooFile       = realpath($basePath . '/src/Foo.php');
        $barFile       = realpath($basePath . '/src/Bar.php');
        $progressFiles = array_map($this->normalisePath(...), $progress->files);

        $this->assertIsString($fooFile);
        $this->assertIsString($barFile);
        $this->assertSame(2, $progress->total);
        $this->assertCount(2, $progressFiles);
        $this->assertContains($this->normalisePath($fooFile), $progressFiles);
        $this->assertContains($this->normalisePath($barFile), $progressFiles);
        $this->assertTrue($progress->finished);
    }

    public function testFilesForAnalysisIgnoresDirectorySymlinks(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php'      => '<?php namespace App; final class Foo {}',
            'src/Docs/read.md' => '# ignored',
        ]);
        mkdir($basePath . '/LinkedDirectory');
        symlink($basePath . '/LinkedDirectory', $basePath . '/src/LinkedDirectory');

        $architecture = Architecture::define()
            ->layer('Source', 'src/');

        $files = (new Analyser($basePath))->filesForAnalysis($architecture);

        $this->assertCount(1, $files);
        $this->assertStringEndsWith('/src/Foo.php', $this->normalisePath($files[0]));
    }

    public function testFilesForAnalysisWithAbsoluteScanPath(): void
    {
        $basePath = $this->makeTempProject([
            'index.php'   => '<?php namespace App; final class Index {}',
            'src/Foo.php' => '<?php namespace App; final class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/');

        $absoluteScanPath = $basePath . '/index.php';

        $files = (new Analyser($basePath))->filesForAnalysis($architecture, [$absoluteScanPath]);

        $this->assertCount(1, $files);
        $this->assertStringEndsWith('/index.php', $this->normalisePath($files[0]));
    }

    public function testFilesForAnalysisWithRootRelativeScanPath(): void
    {
        $basePath         = $this->makeTempProject([
            'composer.json'        => '{}',
            'index.php'            => '<?php namespace App; final class Index {}',
            'nested/composer.json' => '{}',
            'src/Foo.php'          => '<?php namespace App; final class Foo {}',
        ]);
        $rootComposerFile = realpath($basePath . '/composer.json');

        $this->assertIsString($rootComposerFile);

        $architecture = Architecture::define()
            ->layer('Source', 'src/');

        $analyser = new Analyser($basePath);
        $files    = $analyser->filesForAnalysis($architecture, ['index.php']);

        $this->assertCount(1, $files);
        $this->assertStringEndsWith('/index.php', $this->normalisePath($files[0]));
        $this->assertSame(
            [$this->normalisePath($rootComposerFile)],
            array_map($this->normalisePath(...), $analyser->filesForAnalysis($architecture, ['composer.json']))
        );
        $this->assertSame([], $analyser->filesForAnalysis($architecture, ['nested/composer.json']));
    }

    public function testFilesForAnalysisUsesPreResolvedLayersWhenProvided(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php' => '<?php namespace App; final class Foo {}',
            'src/Bar.php' => '<?php namespace App; final class Bar {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/');

        $analyser       = new Analyser($basePath);
        $resolvedLayers = ['Source' => ['src/']];

        $filesWithLayers    = $analyser->filesForAnalysis($architecture, [], $resolvedLayers);
        $filesWithoutLayers = $analyser->filesForAnalysis($architecture);

        $this->assertCount(2, $filesWithoutLayers);
        $this->assertSame($filesWithoutLayers, $filesWithLayers);
    }

    public function testAnalyserReportsProgressOnlyForFilesMissingFromClassNodeCache(): void
    {
        $basePath            = $this->makeTempProject([
            'src/Foo.php' => '<?php namespace App; final class Foo {}',
            'src/Bar.php' => '<?php namespace App; final class Bar {}',
        ]);
        $analysisResultCache = new AnalysisResultCache($basePath, new FileHashProvider(), 'cache');
        $progress            = new class implements ProgressHandlerInterface {
            public int $total = 0;

            /** @var list<string> */
            public array $files = [];

            public function start(int $total): void
            {
                $this->total = $total;
            }

            public function advance(string $file): void
            {
                $this->files[] = $file;
            }

            public function finish(): void
            {
            }
        };

        $architecture = Architecture::define()
            ->layer('Source', 'src/');

        (new Analyser($basePath, $analysisResultCache, 'config'))->analyse($architecture);
        file_put_contents($basePath . '/src/Baz.php', '<?php namespace App; final class Baz {}');

        (new Analyser($basePath, $analysisResultCache, 'config'))->analyse($architecture, [], $progress);

        $this->assertSame(1, $progress->total);
        $this->assertCount(1, $progress->files);
        $this->assertStringEndsWith('/src/Baz.php', $this->normalisePath($progress->files[0]));

        $architecture->rule('file-tags', new Psr1PhpTagsRule(['src/']));
        $analyser = new Analyser($basePath, $analysisResultCache, 'config-with-file-analysis');
        $analyser->analyse($architecture);

        $progress->total = -1;
        $progress->files = [];

        $analyser->analyse($architecture, [], $progress);

        $this->assertSame(0, $progress->total);
        $this->assertSame([], $progress->files);
    }

    public function testAnalyserReusesFileAnalysisThroughSymlinkedProjectPath(): void
    {
        $basePath         = $this->makeTempProject([
            'src/Foo.php' => '<?php namespace App; final class Foo {}',
        ]);
        $linkedBasePath   = $basePath . '-link';
        $analysisBasePath = $basePath;

        if (DIRECTORY_SEPARATOR !== '\\') {
            symlink($basePath, $linkedBasePath);
            $analysisBasePath = $linkedBasePath;
        }

        $rule = new class ($analysisBasePath . '/src/Foo.php') implements FileAnalysisRuleInterface {
            public bool $reusedAnalysis = false;

            public function __construct(private readonly string $file)
            {
            }

            public function evaluateProject(
                string $basePath,
                Architecture $architecture,
                array $skipPaths = [],
            ): ?RuleViolation {
                return null;
            }

            public function evaluateProjectAll(
                string $basePath,
                Architecture $architecture,
                array $skipPaths = [],
            ): array {
                return [];
            }

            public function evaluateProjectAllWithProvider(
                string $basePath,
                Architecture $architecture,
                FileAnalysisProvider $fileAnalysisProvider,
                array $skipPaths = [],
            ): array {
                file_put_contents($this->file, '<?php echo "changed after extraction";');

                $this->reusedAnalysis = $fileAnalysisProvider->analyse($this->file)->declaresSymbols;

                return [];
            }
        };

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('reuse-probe', $rule);

        try {
            (new Analyser($analysisBasePath))->analyse(
                $architecture,
                analyserOptions: AnalyserOptions::sequential(),
            );

            $this->assertTrue($rule->reusedAnalysis);
        } finally {
            if (DIRECTORY_SEPARATOR !== '\\') {
                unlink($linkedBasePath);
            }
        }
    }

    public function testAnalyserReportsAllViolationsFromMultipleViolationRules(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php' => <<<'PHP'
                <?php

                namespace App;

                class Foo
                {
                    public function first(): void
                    {
                        $a = 1;
                        $b = 2;
                    }

                    public function second(): void
                    {
                        $a = 1;
                        $b = 2;
                        $c = 3;
                    }
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('source.max_method_length', new MaxMethodLengthRule('Source', 1));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $violations = $ruleViolationCollection->forRule('source.max_method_length');

        $this->assertCount(2, $violations);
        $this->assertStringContainsString('first', $violations[0]->message);
        $this->assertStringContainsString('second', $violations[1]->message);
    }

    public function testAnalyserTreatsAliasedImportAsDependency(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php' => <<<'PHP'
                <?php

                namespace App;

                use Vendor\ForbiddenService as Service;

                final class Foo
                {
                    public function __construct(private Service $service)
                    {
                    }

                    public function run(): void
                    {
                    }
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule(
                'source.must_not_use_forbidden_service',
                new MayNotUseClassRule('Source', 'Vendor\ForbiddenService')
            );

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertCount(
            1,
            $ruleViolationCollection->forRule('source.must_not_use_forbidden_service')
        );
    }

    public function testAnalyserTreatsGroupedAliasedImportAsDependency(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php' => <<<'PHP'
                <?php

                namespace App;

                use FooLibrary\Bar\Baz\{ClassA, ClassB, ClassC, ClassD as Fizbo};

                final class Foo
                {
                    public function __construct(private Fizbo $service)
                    {
                    }
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule(
                'source.must_not_use_grouped_alias',
                new MayNotUseClassRule('Source', 'FooLibrary\Bar\Baz\ClassD')
            );

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertCount(
            1,
            $ruleViolationCollection->forRule('source.must_not_use_grouped_alias')
        );
    }

    public function testDefaultPsr4PresetDetectsClassesThatDoNotMatchScannedComposerPath(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json' => '{"autoload-dev":{"psr-4":{"App\\\\Tests\\\\":"tests/"}}}',
            'tests/Foo.php' => '<?php class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->withPreset(Preset::PSR4());

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['tests/']);

        $this->assertCount(1, $ruleViolationCollection->forRule('psr4.classes.must_match_composer'));
        $this->assertStringContainsString(
            'App\\Tests\\Foo',
            $ruleViolationCollection->forRule('psr4.classes.must_match_composer')[0]->message
        );
    }

    public function testAnalyserSkipsConfiguredPathsInsideExplicitScanPath(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php'              => '<?php namespace App; class Foo {}',
            'src/Fixtures/Ignored.php' => '<?php namespace App\Fixtures; class Ignored {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->skip(['src/Fixtures/'])
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertCount(1, $ruleViolationCollection->forRule('source.must_be_final'));
        $this->assertStringEndsWith(
            '/src/Foo.php',
            $this->normalisePath($ruleViolationCollection->forRule('source.must_be_final')[0]->file)
        );
    }

    public function testAnalyserAppliesGlobalSkipPathsToPreResolvedFiles(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php'              => '<?php namespace App; class Foo {}',
            'src/Fixtures/Ignored.php' => '<?php namespace App\Fixtures; class Ignored {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->skip(['src/Fixtures/'])
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse(
            $architecture,
            analyserOptions: AnalyserOptions::sequential(),
            files: [
                $basePath . '/src/Foo.php',
                $basePath . '/src/Fixtures/Ignored.php',
            ],
        );

        $violations = $ruleViolationCollection->forRule('source.must_be_final');

        $this->assertCount(1, $violations);
        $this->assertStringEndsWith('/src/Foo.php', $this->normalisePath($violations[0]->file));
    }

    public function testAnalyserSkipsEntireConfiguredScanPath(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php' => '<?php namespace App; class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->skip(['src/'])
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserCanCompareBasePathWhenCheckingSkips(): void
    {
        $basePath = $this->makeTempProject([
            'Foo.php' => '<?php class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', '.')
            ->skip(['does-not-match'])
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertCount(1, $ruleViolationCollection->forRule('source.must_be_final'));
    }

    public function testAnalyserSkipsConfiguredGlobPaths(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php'                   => '<?php namespace App; class Foo {}',
            'src/Generated/Ignored.php'     => '<?php namespace App\Generated; class Ignored {}',
            'src/Generated/Nested/Nope.php' => '<?php namespace App\Generated\Nested; class Nope {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->skip(['src/Generated/*'])
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertCount(1, $ruleViolationCollection->forRule('source.must_be_final'));
        $this->assertStringEndsWith(
            '/src/Foo.php',
            $this->normalisePath($ruleViolationCollection->forRule('source.must_be_final')[0]->file)
        );
    }

    public function testAnalyserKeepsFilesWhenGlobSkipDoesNotMatch(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php' => '<?php namespace App; class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->skip(['tests/*'])
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertCount(1, $ruleViolationCollection->forRule('source.must_be_final'));
    }

    public function testAnalyserSkipsAbsoluteSkipPath(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php'              => '<?php namespace App; class Foo {}',
            'src/Fixtures/Ignored.php' => '<?php namespace App\Fixtures; class Ignored {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->skip([$basePath . '/src/Fixtures'])
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertCount(1, $ruleViolationCollection->forRule('source.must_be_final'));
        $this->assertStringEndsWith(
            '/src/Foo.php',
            $this->normalisePath($ruleViolationCollection->forRule('source.must_be_final')[0]->file)
        );
    }

    public function testAnalyserRootSkipPathSkipsAllFiles(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php' => '<?php namespace App; class Foo {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->skip(['/'])
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserAppliesGlobSkipToScanPathOutsideBasePath(): void
    {
        $basePath    = $this->makeTempProject([]);
        $outsidePath = $this->makeTempProject([
            'Foo.php'               => '<?php namespace App; class Foo {}',
            'Ignored.generated.php' => '<?php namespace App; class Ignored {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', $outsidePath . '/')
            ->skip(['*.generated.php'])
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, [$outsidePath . '/']);

        $this->assertCount(1, $ruleViolationCollection->forRule('source.must_be_final'));
        $this->assertStringEndsWith(
            '/Foo.php',
            $this->normalisePath($ruleViolationCollection->forRule('source.must_be_final')[0]->file)
        );
    }

    public function testAnalyserSkipsAbsoluteSkipPathWithDotDotSegment(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php'              => '<?php namespace App; class Foo {}',
            'src/Fixtures/Ignored.php' => '<?php namespace App\Fixtures; class Ignored {}',
        ]);

        // A `..` segment that the old fallback str_starts_with could not resolve —
        // only a properly normalised (realpath-resolved) absolute skip path will match.
        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->skip([$basePath . '/src/../src/Fixtures'])
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertCount(1, $ruleViolationCollection->forRule('source.must_be_final'));
        $this->assertStringEndsWith(
            '/src/Foo.php',
            $this->normalisePath($ruleViolationCollection->forRule('source.must_be_final')[0]->file)
        );
    }

    public function testAnalyserSkipsConfiguredPathsForSpecificRuleOnly(): void
    {
        $basePath = $this->makeTempProject([
            'src/Foo.php'        => '<?php namespace App; class Foo {}',
            'src/Legacy/Old.php' => '<?php namespace App\Legacy; class Old {}',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->skip(['source.must_be_final' => ['src/Legacy/']])
            ->rule('source.must_be_final', new MustBeFinalRule('Source'))
            ->rule('source.must_be_final_too', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertCount(1, $ruleViolationCollection->forRule('source.must_be_final'));
        $this->assertCount(2, $ruleViolationCollection->forRule('source.must_be_final_too'));
        $this->assertStringEndsWith(
            '/src/Foo.php',
            $this->normalisePath($ruleViolationCollection->forRule('source.must_be_final')[0]->file)
        );
    }

    public function testAnalyserMergesGlobalAndRuleSpecificSkipPaths(): void
    {
        $basePath = $this->makeTempProject([
            'src/GloballySkipped.php' => '<? echo "global";',
            'src/RuleSkipped.php'     => '<? echo "rule";',
            'src/Checked.php'         => '<? echo "checked";',
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->skipPath('src/GloballySkipped.php')
            ->skip(['psr1.tags' => ['src/RuleSkipped.php']])
            ->rule('psr1.tags', new Psr1PhpTagsRule(['src/']));

        $violations = (new Analyser($basePath))->analyse(
            $architecture,
            analyserOptions: AnalyserOptions::sequential(),
        )->forRule('psr1.tags');

        $this->assertCount(1, $violations);
        $this->assertStringEndsWith('/src/Checked.php', $this->normalisePath($violations[0]->file));
    }

    public function testAnalyserSkipsRuleConfiguredBeforePresetRegistersIt(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
            'src/Foo.php'   => '<?php namespace App; class Foo { public function Bad_method(): void {} }',
        ]);

        $architecture = Architecture::define()
            ->skip([
                'tests/Fixtures/',
                Psr1Preset::METHODS_MUST_BE_CAMEL_CASE,
            ])
            ->withPreset(Preset::PSR1(sourcePaths: ['src/']));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertCount(0, $ruleViolationCollection->forRule(Psr1Preset::METHODS_MUST_BE_CAMEL_CASE));
    }

    public function testFilesForAnalysisIgnoresFileSymlinks(): void
    {
        $basePath    = $this->makeTempProject([
            'src/.keep' => '',
        ]);
        $outsidePath = $this->makeTemporaryFile('structarmed-outside');
        file_put_contents($outsidePath, '<?php class Linked {}');
        symlink($outsidePath, $basePath . '/src/Linked.php');

        $architecture = Architecture::define()
            ->layer('Source', 'src/');

        $this->assertSame([], (new Analyser($basePath))->filesForAnalysis($architecture));
    }

    public function testAnalyserEvaluatesRulesetAndDetectsLayerViolation(): void
    {
        $basePath = $this->makeTempProject([
            'src/HTTP/Request.php' => <<<'PHP'
                <?php

                namespace App\HTTP;

                use App\Database\QueryBuilder;

                final class Request
                {
                    public function __construct(private QueryBuilder $db) {}
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->layerPattern('Database', '/^App\\\\Database\\\\.*$/')
            ->ruleset([
                'HTTP' => ['Cookie', 'Files', 'I18n'], // Database NOT allowed
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertTrue($ruleViolationCollection->hasViolations());
        $violations = $ruleViolationCollection->forRule('ruleset.HTTP');
        $this->assertCount(1, $violations);
        $this->assertSame(
            'Class [App\\HTTP\\Request] in layer [HTTP] must not depend on [App\\Database\\QueryBuilder] '
            . 'which belongs to layer [Database]',
            $violations[0]->message
        );
    }

    public function testAnalyserRulesetSkipsGloballySkippedFileFromPreResolvedList(): void
    {
        $basePath = $this->makeTempProject([
            'src/HTTP/Request.php' => <<<'PHP'
                <?php

                namespace App\HTTP;

                use App\Database\QueryBuilder;

                final class Request
                {
                    public function __construct(private QueryBuilder $db) {}
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->layerPattern('Database', '/^App\\\\Database\\\\.*$/')
            ->skip(['src/HTTP/'])
            ->ruleset([
                'HTTP' => [], // Database NOT allowed
            ]);

        // A pre-resolved file list bypasses filesForAnalysis(), so the ruleset
        // loop itself must honour the global skip paths.
        $ruleViolationCollection = (new Analyser($basePath))->analyse(
            $architecture,
            ['src/'],
            files: [$basePath . '/src/HTTP/Request.php']
        );

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    #[DataProvider('rulesetClassLikeKindProvider')]
    public function testAnalyserRulesetViolationMessageNamesTheClassLikeKind(string $expectedKind, string $source): void
    {
        $basePath = $this->makeTempProject(['src/HTTP/Request.php' => $source]);

        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->layerPattern('Database', '/^App\\\\Database\\\\.*$/')
            ->ruleset([
                'HTTP' => [], // Database NOT allowed
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $violations = $ruleViolationCollection->forRule('ruleset.HTTP');
        $this->assertCount(1, $violations);
        $this->assertSame(
            $expectedKind . ' [App\\HTTP\\Request] in layer [HTTP] must not depend on [App\\Database\\QueryBuilder] '
            . 'which belongs to layer [Database]',
            $violations[0]->message
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function rulesetClassLikeKindProvider(): iterable
    {
        yield 'interface' => [
            'Interface',
            <<<'PHP'
                <?php

                namespace App\HTTP;

                use App\Database\QueryBuilder;

                interface Request
                {
                    public function db(): QueryBuilder;
                }
                PHP,
        ];

        yield 'trait' => [
            'Trait',
            <<<'PHP'
                <?php

                namespace App\HTTP;

                use App\Database\QueryBuilder;

                trait Request
                {
                    public function db(): QueryBuilder
                    {
                        return new QueryBuilder();
                    }
                }
                PHP,
        ];

        yield 'enum' => [
            'Enum',
            <<<'PHP'
                <?php

                namespace App\HTTP;

                use App\Database\QueryBuilder;

                enum Request
                {
                    case Get;

                    public function db(): QueryBuilder
                    {
                        return new QueryBuilder();
                    }
                }
                PHP,
        ];
    }

    public function testAnalyserEvaluatesRulesetAndDetectsLayerViolationWithPathBasedLayers(): void
    {
        $basePath = $this->makeTempProject([
            'src/HTTP/Request.php'          => <<<'PHP'
                <?php

                namespace App\HTTP;

                use App\Database\QueryBuilder;

                final class Request
                {
                    public function __construct(private QueryBuilder $db) {}
                }
                PHP,
            'src/Database/QueryBuilder.php' => <<<'PHP'
                <?php

                namespace App\Database;

                final class QueryBuilder {}
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('HTTP', 'src/HTTP/')
            ->layer('Database', 'src/Database/')
            ->ruleset([
                'HTTP' => [], // Database NOT allowed
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertTrue($ruleViolationCollection->hasViolations());
        $violations = $ruleViolationCollection->forRule('ruleset.HTTP');
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('Database', $violations[0]->message);
    }

    public function testAnalyserRulesetKeepsPathLayerWhenDependencyAlsoMatchesRegexLayer(): void
    {
        $basePath = $this->makeTempProject([
            'src/Application/PlaceOrderHandler.php' => <<<'PHP'
                <?php

                namespace App\Application;

                use App\Domain\OrderRepository;

                final class PlaceOrderHandler
                {
                    public function __construct(OrderRepository $orderRepository)
                    {
                    }
                }
                PHP,
            'src/Domain/OrderRepository.php'        => <<<'PHP'
                <?php

                namespace App\Domain;

                interface OrderRepository {}
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('Application', 'src/Application/')
            ->layer('Domain', 'src/Domain/')
            ->layerPattern('Repository', '/Repository$/')
            ->ruleset([
                'Application' => ['Domain'],
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertCount(0, $ruleViolationCollection->forRule('ruleset.Application'));
    }

    public function testAnalyserRulesetAllowsSameLayerDependencyWhenCallerLayerIsSecondary(): void
    {
        $basePath = $this->makeTempProject([
            'src/Application/OrderService.php'      => <<<'PHP'
                <?php

                namespace App\Application;

                final class OrderService
                {
                    public function __construct(private PlaceOrderHandler $handler)
                    {
                    }
                }
                PHP,
            'src/Application/PlaceOrderHandler.php' => <<<'PHP'
                <?php

                namespace App\Application;

                final class PlaceOrderHandler
                {
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('Application', 'src/Application/')
            ->layerPattern('Handler', '/Handler$/')
            ->ruleset([
                'Application' => [],
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertCount(0, $ruleViolationCollection->forRule('ruleset.Application'));
    }

    public function testAnalyserRulesetUsesPrimaryLayerWhenCallerAlsoMatchesSecondaryLayer(): void
    {
        $basePath = $this->makeTempProject([
            'src/Application/OrderHandler.php'  => <<<'PHP'
                <?php

                namespace App\Application;

                use App\Infrastructure\Repository;

                final class OrderHandler
                {
                    public function __construct(Repository $repository)
                    {
                    }
                }
                PHP,
            'src/Infrastructure/Repository.php' => <<<'PHP'
                <?php

                namespace App\Infrastructure;

                final class Repository
                {
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('Application', 'src/Application/')
            ->layer('Infrastructure', 'src/Infrastructure/')
            ->layerPattern('Handler', '/Handler$/')
            ->ruleset([
                'Application' => [],
            ]);

        // layerPattern() resolvers take precedence over path resolvers, so OrderHandler's
        // primary layer is Handler. Application is secondary and its ruleset does not apply.
        $violations = (new Analyser($basePath))
            ->analyse($architecture)
            ->forRule('ruleset.Application');

        $this->assertCount(0, $violations);
    }

    public function testAnalyserRulesetUsesPathLayerWhenCallerIsExcludedFromPatternLayer(): void
    {
        $basePath = $this->makeTempProject([
            'src/Application/OrderHandler.php'  => <<<'PHP'
                <?php

                namespace App\Application;

                use App\Infrastructure\Repository;

                final class OrderHandler
                {
                    public function __construct(Repository $repository)
                    {
                    }
                }
                PHP,
            'src/Infrastructure/Repository.php' => <<<'PHP'
                <?php

                namespace App\Infrastructure;

                final class Repository
                {
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('Application', 'src/Application/')
            ->layer('Infrastructure', 'src/Infrastructure/')
            ->layerPattern('Handler', '/Handler$/', '/^App\\\\Application\\\\/')
            ->ruleset([
                'Application' => [],
            ]);

        $violations = (new Analyser($basePath))
            ->analyse($architecture)
            ->forRule('ruleset.Application');

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('which belongs to layer [Infrastructure]', $violations[0]->message);
    }

    public function testAnalyserRulesetSameLayerAllowedWhenRegexLayerExcludesDependency(): void
    {
        $basePath = $this->makeTempProject([
            'src/Application/OrderService.php'      => <<<'PHP'
                <?php

                namespace App\Application;

                final class OrderService
                {
                    public function __construct(private PlaceOrderHandler $handler)
                    {
                    }
                }
                PHP,
            'src/Application/PlaceOrderHandler.php' => <<<'PHP'
                <?php

                namespace App\Application;

                final class PlaceOrderHandler
                {
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('Application', 'src/Application/')
            ->layerPattern('Handler', '/Handler$/', '/^App\\\\Application\\\\/')
            ->ruleset([
                'Application' => [],
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertCount(0, $ruleViolationCollection->forRule('ruleset.Application'));
    }

    public function testAnalyserRulesetStillViolatesWhenSecondaryLayerDiffersFromCallerLayer(): void
    {
        $basePath = $this->makeTempProject([
            'src/Application/OrderService.php'         => <<<'PHP'
                <?php

                namespace App\Application;

                use App\Infrastructure\PlaceOrderHandler;

                final class OrderService
                {
                    public function __construct(private PlaceOrderHandler $handler)
                    {
                    }
                }
                PHP,
            'src/Infrastructure/PlaceOrderHandler.php' => <<<'PHP'
                <?php

                namespace App\Infrastructure;

                final class PlaceOrderHandler
                {
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('Application', 'src/Application/')
            ->layer('Infrastructure', 'src/Infrastructure/')
            ->layerPattern('Handler', '/Handler$/')
            ->ruleset([
                'Application' => [],
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $violations = $ruleViolationCollection->forRule('ruleset.Application');
        $this->assertCount(1, $violations);
    }

    public function testAnalyserRulesetViolatesWhenApplicationPatternExcludesHandler(): void
    {
        $basePath = $this->makeTempProject([
            'src/Application/OrderService.php'      => <<<'PHP'
                <?php

                namespace App\Application;

                final class OrderService
                {
                    public function __construct(private PlaceOrderHandler $handler)
                    {
                    }
                }
                PHP,
            'src/Application/PlaceOrderHandler.php' => <<<'PHP'
                <?php

                namespace App\Application;

                final class PlaceOrderHandler
                {
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('Application', '/^App\\\\Application\\\\/', '/Handler$/')
            ->layerPattern('Handler', '/Handler$/')
            ->ruleset([
                'Application' => [],
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src']);

        $violations = $ruleViolationCollection->forRule('ruleset.Application');
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('Handler', $violations[0]->message);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function importedSymbolDependencyProvider(): iterable
    {
        yield 'constant fetch' => [
            <<<'PHP'
                <?php

                namespace App\HTTP;

                use const App\Database\Config\DEFAULT_TIMEOUT;

                final class Request
                {
                    public function timeout(): int
                    {
                        return DEFAULT_TIMEOUT;
                    }
                }
                PHP,
            ['App\Database\Config\DEFAULT_TIMEOUT'],
        ];

        yield 'function call' => [
            <<<'PHP'
                <?php

                namespace App\HTTP;

                use function App\Database\Support\query;

                final class Request
                {
                    public function run(): void
                    {
                        query();
                    }
                }
                PHP,
            ['App\Database\Support\query'],
        ];

        yield 'grouped constant fetch' => [
            <<<'PHP'
                <?php

                namespace App\HTTP;

                use const App\Database\Config\{DEFAULT_TIMEOUT, RETRY_LIMIT};

                final class Request
                {
                    public function timeout(): int
                    {
                        return DEFAULT_TIMEOUT + RETRY_LIMIT;
                    }
                }
                PHP,
            [
                'App\Database\Config\DEFAULT_TIMEOUT',
                'App\Database\Config\RETRY_LIMIT',
            ],
        ];

        yield 'grouped function call' => [
            <<<'PHP'
                <?php

                namespace App\HTTP;

                use function App\Database\Support\{query, trace};

                final class Request
                {
                    public function run(): void
                    {
                        query();
                        trace();
                    }
                }
                PHP,
            [
                'App\Database\Support\query',
                'App\Database\Support\trace',
            ],
        ];
    }

    /**
     * @param list<string> $dependencies
     */
    #[DataProvider('importedSymbolDependencyProvider')]
    public function testAnalyserRulesetTreatsImportedConstantsAndFunctionsAsDependencies(
        string $sourceCode,
        array $dependencies
    ): void {
        $basePath = $this->makeTempProject([
            'src/HTTP/Request.php' => $sourceCode,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->layerPattern('Database', '/^App\\\\Database\\\\.*$/')
            ->ruleset([
                'HTTP' => [],
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $violations = $ruleViolationCollection->forRule('ruleset.HTTP');

        $this->assertCount(count($dependencies), $violations);

        foreach ($dependencies as $index => $dependency) {
            $this->assertStringContainsString($dependency, $violations[$index]->message);
            $this->assertStringContainsString('Database', $violations[$index]->message);
        }
    }

    public function testAnalyserRulesetAllowsListedLayerDependency(): void
    {
        $basePath = $this->makeTempProject([
            'src/HTTP/Request.php' => <<<'PHP'
                <?php

                namespace App\HTTP;

                use App\Cookie\CookieJar;

                final class Request
                {
                    public function __construct(private CookieJar $cookies) {}
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->layerPattern('Cookie', '/^App\\\\Cookie\\\\.*$/')
            ->ruleset([
                'HTTP' => ['Cookie'], // Cookie IS allowed
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserRulesetIgnoresExternalDependencies(): void
    {
        $basePath = $this->makeTempProject([
            'src/HTTP/Request.php' => <<<'PHP'
                <?php

                namespace App\HTTP;

                use Psr\Http\Message\RequestInterface;

                final class Request implements RequestInterface
                {
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->ruleset([
                'HTTP' => [], // nothing allowed, but external deps are fine
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserRulesetSkipClassViolationSuppressesViolation(): void
    {
        $basePath = $this->makeTempProject([
            'src/HTTP/Request.php' => <<<'PHP'
                <?php

                namespace App\HTTP;

                use App\Database\QueryBuilder;

                final class Request
                {
                    public function __construct(private QueryBuilder $db) {}
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->layerPattern('Database', '/^App\\\\Database\\\\.*$/')
            ->ruleset(['HTTP' => []])
            ->skipClassViolation('App\\HTTP\\Request', 'App\\Database\\QueryBuilder');

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserRulesetSkipClassViolationSuppressesViolationWithPathBasedLayers(): void
    {
        $basePath = $this->makeTempProject([
            'src/HTTP/Request.php'          => <<<'PHP'
                <?php

                namespace App\HTTP;

                use App\Database\QueryBuilder;

                final class Request
                {
                    public function __construct(private QueryBuilder $db) {}
                }
                PHP,
            'src/Database/QueryBuilder.php' => <<<'PHP'
                <?php

                namespace App\Database;

                final class QueryBuilder {}
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('HTTP', 'src/HTTP/')
            ->layer('Database', 'src/Database/')
            ->ruleset(['HTTP' => []])
            ->skipClassViolation('App\\HTTP\\Request', 'App\\Database\\QueryBuilder');

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertCount(0, $ruleViolationCollection->forRule('ruleset.HTTP'));
    }

    public function testAnalyserRulesetReportsTransitiveSameLayerDependencyViolations(): void
    {
        $basePath = $this->makeTempProject([
            'src/HTTP/ResponseTrait.php'    => <<<'PHP'
                <?php

                namespace App\HTTP;

                use App\Pager\PagerInterface;

                trait ResponseTrait
                {
                    public function setLink(PagerInterface $pager): void {}
                }
                PHP,
            'src/HTTP/Response.php'         => <<<'PHP'
                <?php

                namespace App\HTTP;

                final class Response
                {
                    use ResponseTrait;
                }
                PHP,
            'src/HTTP/DownloadResponse.php' => <<<'PHP'
                <?php

                namespace App\HTTP;

                final class DownloadResponse extends Response
                {
                }
                PHP,
            'src/Pager/PagerInterface.php'  => <<<'PHP'
                <?php

                namespace App\Pager;

                interface PagerInterface
                {
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->layerPattern('Pager', '/^App\\\\Pager\\\\.*$/')
            ->ruleset(['HTTP' => []])
            ->skipClassViolation('App\\HTTP\\DownloadResponse', 'App\\Pager\\PagerInterface');

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $violations = $ruleViolationCollection->forRule('ruleset.HTTP');
        $classes    = array_map(
            static fn(RuleViolation $ruleViolation): string => $ruleViolation->className,
            $violations
        );
        sort($classes);

        $this->assertCount(2, $violations);
        $this->assertSame(['App\\HTTP\\Response', 'App\\HTTP\\ResponseTrait'], $classes);
    }

    public function testAnalyserRulesetReportsInterfaceExtendsDependencyViolations(): void
    {
        $basePath = $this->makeTempProject([
            'src/HTTP/ResponseInterface.php' => <<<'PHP'
                <?php

                namespace App\HTTP;

                interface ResponseInterface extends \App\Pager\PagerInterface
                {
                }
                PHP,
            'src/Pager/PagerInterface.php'   => <<<'PHP'
                <?php

                namespace App\Pager;

                interface PagerInterface
                {
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->layerPattern('Pager', '/^App\\\\Pager\\\\.*$/')
            ->ruleset(['HTTP' => []]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);
        $violations              = $ruleViolationCollection->forRule('ruleset.HTTP');

        $this->assertCount(1, $violations);
        $this->assertSame('App\\HTTP\\ResponseInterface', $violations[0]->className);
        $this->assertStringContainsString('App\\Pager\\PagerInterface', $violations[0]->message);
    }

    public function testAnalyserRulesetReportsDependenciesInheritedFromInterfaceExtends(): void
    {
        $basePath = $this->makeTempProject([
            'src/HTTP/ResponseInterface.php'     => <<<'PHP'
                <?php

                namespace App\HTTP;

                interface ResponseInterface extends \App\Shared\PagerAwareInterface
                {
                }
                PHP,
            'src/Shared/PagerAwareInterface.php' => <<<'PHP'
                <?php

                namespace App\Shared;

                interface PagerAwareInterface
                {
                    public function setLink(\App\Pager\PagerInterface $pager): void;
                }
                PHP,
            'src/Pager/PagerInterface.php'       => <<<'PHP'
                <?php

                namespace App\Pager;

                interface PagerInterface
                {
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->layerPattern('Shared', '/^App\\\\Shared\\\\.*$/')
            ->layerPattern('Pager', '/^App\\\\Pager\\\\.*$/')
            ->ruleset(['HTTP' => ['Shared']]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);
        $violations              = $ruleViolationCollection->forRule('ruleset.HTTP');

        $this->assertCount(1, $violations);
        $this->assertSame('App\\HTTP\\ResponseInterface', $violations[0]->className);
        $this->assertStringContainsString('App\\Pager\\PagerInterface', $violations[0]->message);
    }

    public function testAnalyserRulesetReportsInheritedDependenciesFromSharedParent(): void
    {
        $basePath = $this->makeTempProject([
            'src/HTTP/BaseController.php'   => <<<'PHP'
                <?php

                namespace App\HTTP;

                use App\Database\QueryBuilder;

                abstract class BaseController
                {
                    public function __construct(protected QueryBuilder $db) {}
                }
                PHP,
            'src/HTTP/CreateController.php' => <<<'PHP'
                <?php

                namespace App\HTTP;

                final class CreateController extends BaseController
                {
                }
                PHP,
            'src/HTTP/EditController.php'   => <<<'PHP'
                <?php

                namespace App\HTTP;

                final class EditController extends BaseController
                {
                }
                PHP,
            'src/Database/QueryBuilder.php' => <<<'PHP'
                <?php

                namespace App\Database;

                final class QueryBuilder
                {
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->layerPattern('Database', '/^App\\\\Database\\\\.*$/')
            ->ruleset(['HTTP' => []]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $violations = $ruleViolationCollection->forRule('ruleset.HTTP');
        $classes    = array_map(
            static fn(RuleViolation $ruleViolation): string => $ruleViolation->className,
            $violations
        );
        sort($classes);

        $this->assertSame([
            'App\\HTTP\\BaseController',
            'App\\HTTP\\CreateController',
            'App\\HTTP\\EditController',
        ], $classes);

        foreach ($violations as $violation) {
            $this->assertStringContainsString('App\\Database\\QueryBuilder', $violation->message);
        }
    }

    public function testAnalyserRulesetKeepsInheritedDependencyViolationOrderWhenResultsAreReused(): void
    {
        $basePath = $this->makeTempProject([
            'src/HTTP/BaseController.php'   => <<<'PHP'
                <?php

                namespace App\HTTP;

                use App\Database\Connection;
                use App\Database\QueryBuilder;

                abstract class BaseController
                {
                    public function __construct(
                        protected Connection $connection,
                        protected QueryBuilder $queryBuilder,
                    ) {}
                }
                PHP,
            'src/HTTP/FirstController.php'  => <<<'PHP'
                <?php

                namespace App\HTTP;

                final class FirstController extends BaseController
                {
                }
                PHP,
            'src/HTTP/SecondController.php' => <<<'PHP'
                <?php

                namespace App\HTTP;

                final class SecondController extends BaseController
                {
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->layerPattern('Database', '/^App\\\\Database\\\\.*$/')
            ->ruleset(['HTTP' => []]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse(
            $architecture,
            ['src/'],
            null,
            AnalyserOptions::sequential(),
            [
                $basePath . '/src/HTTP/BaseController.php',
                $basePath . '/src/HTTP/FirstController.php',
                $basePath . '/src/HTTP/SecondController.php',
            ]
        );

        $secondControllerViolations = [];
        foreach ($ruleViolationCollection->forRule('ruleset.HTTP') as $ruleViolation) {
            if ($ruleViolation->className === 'App\\HTTP\\SecondController') {
                $secondControllerViolations[] = $ruleViolation;
            }
        }

        $this->assertCount(2, $secondControllerViolations);
        $this->assertStringContainsString('App\\Database\\Connection', $secondControllerViolations[0]->message);
        $this->assertStringContainsString('App\\Database\\QueryBuilder', $secondControllerViolations[1]->message);
    }

    public function testAnalyserRulesetStopsResolvingCyclicInheritanceDependencies(): void
    {
        $basePath = $this->makeTempProject([
            'src/HTTP/First.php'            => <<<'PHP'
                <?php

                namespace App\HTTP;

                final class First extends Second
                {
                }
                PHP,
            'src/HTTP/Second.php'           => <<<'PHP'
                <?php

                namespace App\HTTP;

                use App\Database\QueryBuilder;

                final class Second extends First
                {
                    public function __construct(private QueryBuilder $db) {}
                }
                PHP,
            'src/Database/QueryBuilder.php' => <<<'PHP'
                <?php

                namespace App\Database;

                final class QueryBuilder
                {
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->layerPattern('Database', '/^App\\\\Database\\\\.*$/')
            ->ruleset(['HTTP' => []]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $violations = $ruleViolationCollection->forRule('ruleset.HTTP');
        $classes    = array_map(
            static fn(RuleViolation $ruleViolation): string => $ruleViolation->className,
            $violations
        );
        sort($classes);

        $this->assertSame(['App\\HTTP\\First', 'App\\HTTP\\Second'], $classes);
    }

    public function testAnalyserStopsResolvingCyclicInterfaceParents(): void
    {
        $basePath = $this->makeTempProject([
            'src/Contracts/First.php'  => <<<'PHP'
                <?php

                namespace App\Contracts;

                interface First extends Second
                {
                }
                PHP,
            'src/Contracts/Second.php' => <<<'PHP'
                <?php

                namespace App\Contracts;

                interface Second extends First
                {
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/');

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserRulesetAllowsSameLayerDependencies(): void
    {
        $basePath = $this->makeTempProject([
            'src/HTTP/Request.php' => <<<'PHP'
                <?php

                namespace App\HTTP;

                use App\HTTP\Headers;

                final class Request
                {
                    public function __construct(private Headers $headers) {}
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->ruleset(['HTTP' => []]); // nothing allowed except same-layer

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserRulesetAllowsSameLayerDependenciesWhenLayerOverlapsWithCatchAll(): void
    {
        // When a parent layer (System → src/System/) overlaps with a specific sub-layer
        // (Files → src/System/Files/), a dependency within the same specific layer is
        // resolved to both layers. It must NOT be reported as a violation just because
        // the parent layer is not listed in the allowed layers.
        $basePath = $this->makeTempProject([
            'src/System/Files/File.php'     => <<<'PHP'
                <?php

                namespace App\System\Files;

                final class File {}
                PHP,
            'src/System/Files/FileInfo.php' => <<<'PHP'
                <?php

                namespace App\System\Files;

                use App\System\Files\File;

                final class FileInfo
                {
                    public function __construct(private File $file) {}
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('System', 'src/System/')
            ->layer('Files', 'src/System/Files/')
            ->ruleset(['Files' => []]); // nothing allowed except same-layer

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserRulesetAllowsDependencyWhenAnyOfItsLayersIsAllowed(): void
    {
        // A dependency resolved to two layers [Formatter, System] because Formatter
        // is a specific sub-path layer inside the System parent layer.
        // Entity lists Formatter as allowed. Even though System is not listed,
        // the dependency must NOT be reported as a violation because at least one
        // of the dependency's layers (Formatter) is explicitly allowed.
        $basePath = $this->makeTempProject([
            'src/System/Entity/User.php'             => <<<'PHP'
                <?php

                namespace App\System\Entity;

                use App\System\Formatter\DateFormatter;

                final class User
                {
                    public function __construct(private DateFormatter $formatter) {}
                }
                PHP,
            'src/System/Formatter/DateFormatter.php' => <<<'PHP'
                <?php

                namespace App\System\Formatter;

                final class DateFormatter {}
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('System', 'src/System/')
            ->layer('Entity', 'src/System/Entity/')
            ->layer('Formatter', 'src/System/Formatter/')
            ->ruleset([
                'Entity' => ['Formatter'], // Formatter allowed, System parent is NOT listed
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserRulesetAllowsDependencyWhenParentLayerIsAllowed(): void
    {
        // DateFormatter has primary layer Formatter, but also belongs to the System
        // parent layer. The ruleset allows System for Entity. Even though Formatter is
        // not listed, the dependency must be permitted because System is allowed and
        // DateFormatter is within System.
        $basePath = $this->makeTempProject([
            'src/System/Entity/User.php'             => <<<'PHP'
                <?php

                namespace App\System\Entity;

                use App\System\Formatter\DateFormatter;

                final class User
                {
                    public function __construct(private DateFormatter $formatter) {}
                }
                PHP,
            'src/System/Formatter/DateFormatter.php' => <<<'PHP'
                <?php

                namespace App\System\Formatter;

                final class DateFormatter {}
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('System', 'src/System/')
            ->layer('Entity', 'src/System/Entity/')
            ->layer('Formatter', 'src/System/Formatter/')
            ->ruleset([
                'Entity' => ['System'], // Formatter is NOT listed, but System (its parent) is
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserRulesetReportsViolationWhenDependencyPrimaryLayerIsOutsideSpecificLayer(): void
    {
        // Router is a sub-layer of System. RouterException depends on RuntimeException,
        // which lives in src/System/Exceptions/ with no specific sub-layer — its primary
        // layer is System. Even though Router is within System, RuntimeException's primary
        // layer (System) is outside Router, so this IS a violation when Router => [].
        // To allow it, the user must explicitly list System in Router's allowed layers.
        $basePath = $this->makeTempProject([
            'src/System/Router/Exceptions/RouterException.php' => <<<'PHP'
                <?php

                namespace App\System\Router\Exceptions;

                use App\System\Exceptions\RuntimeException;

                final class RouterException extends RuntimeException {}
                PHP,
            'src/System/Exceptions/RuntimeException.php'       => <<<'PHP'
                <?php

                namespace App\System\Exceptions;

                class RuntimeException extends \RuntimeException {}
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('System', 'src/System/')
            ->layer('Router', 'src/System/Router/')
            ->ruleset([
                'Router' => [],
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $violations = $ruleViolationCollection->forRule('ruleset.Router');
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('System', $violations[0]->message);
    }

    public function testAnalyserRulesetReportsViolationWhenDependencyPrimaryLayerIsParentOfSubLayer(): void
    {
        // Application covers src/, Router is a specific sub-layer inside it.
        // RouterException depends on RuntimeException whose primary layer is Application
        // (no specific sub-layer exists for it). Even though Router is within Application,
        // RuntimeException is outside Router — a violation fires.
        // To suppress it, Application must be listed in Router's allowed layers.
        $basePath = $this->makeTempProject([
            'src/Router/Exceptions/RouterException.php' => <<<'PHP'
                <?php

                namespace App\Router\Exceptions;

                use App\Exceptions\RuntimeException;

                final class RouterException extends RuntimeException {}
                PHP,
            'src/Exceptions/RuntimeException.php'       => <<<'PHP'
                <?php

                namespace App\Exceptions;

                class RuntimeException extends \RuntimeException {}
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('Application', 'src/')
            ->layer('Router', 'src/Router/')
            ->ruleset([
                'Router' => [],
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $violations = $ruleViolationCollection->forRule('ruleset.Router');
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('Application', $violations[0]->message);
    }

    public function testAnalyserRulesetSkipsClassNodeWithNullLayer(): void
    {
        // A PHP file is scanned but the class inside it does not match any
        // layerPattern, so its ClassNode has layer=null. The ruleset evaluator
        // must skip such nodes without producing a violation.
        $basePath = $this->makeTempProject([
            'src/HTTP/Request.php'    => <<<'PHP'
                <?php

                namespace App\HTTP;

                final class Request {}
                PHP,
            'src/External/Logger.php' => <<<'PHP'
                <?php

                namespace App\External;

                use App\HTTP\Request;

                final class Logger
                {
                    public function __construct(private Request $request) {}
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            // Note: App\External\Logger does NOT match any layerPattern → layer=null
            ->ruleset([
                'HTTP' => [],
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        // App\External\Logger has layer=null and must be silently skipped
        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserRulesetDoesNotRestrictLayerAbsentFromRulesetKeys(): void
    {
        // A class belongs to a layer that IS defined via layerPattern, but that
        // layer is not listed as a key in the ruleset. The ruleset evaluator
        // must leave it unrestricted (allowedLayers=null → continue).
        $basePath = $this->makeTempProject([
            'src/Database/QueryBuilder.php' => <<<'PHP'
                <?php

                namespace App\Database;

                use App\HTTP\Request;

                final class QueryBuilder
                {
                    public function __construct(private Request $request) {}
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->layerPattern('Database', '/^App\\\\Database\\\\.*$/')
            ->ruleset([
                'HTTP' => ['Cookie'], // Database is NOT a ruleset key → unrestricted
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        // App\Database\QueryBuilder is in the Database layer which has no ruleset
        // entry, so the dependency on App\HTTP\Request must not produce a violation.
        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserRulesetSkipPathsForRulesetSuppressesViolationsForMatchingFiles(): void
    {
        // Files under tests/ cross layer boundaries intentionally.
        // skipPathsForRuleset() should suppress their ruleset violations
        // while still allowing production code to be checked.
        $basePath = $this->makeTempProject([
            'src/HTTP/Request.php'          => <<<'PHP'
                <?php

                namespace App\HTTP;

                final class Request {}
                PHP,
            'src/Database/QueryBuilder.php' => <<<'PHP'
                <?php

                namespace App\Database;

                use App\HTTP\Request;

                final class QueryBuilder
                {
                    public function __construct(private Request $request) {}
                }
                PHP,
            'tests/HTTP/RequestTest.php'    => <<<'PHP'
                <?php

                namespace App\HTTP;

                use App\Database\QueryBuilder;

                final class RequestTest
                {
                    public function __construct(private QueryBuilder $db) {}
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->layerPattern('Database', '/^App\\\\Database\\\\.*$/')
            ->ruleset([
                'HTTP'     => [], // Database NOT allowed
                'Database' => [], // HTTP NOT allowed
            ])
            ->skipPathsForRuleset(['*tests*']);

        // Production violation (src/Database/QueryBuilder.php → HTTP layer) must still fire.
        // Test violation (tests/HTTP/RequestTest.php → Database layer) must be suppressed.
        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/', 'tests/']);

        $this->assertTrue($ruleViolationCollection->hasViolations());
        $violations = $ruleViolationCollection->forRule('ruleset.Database');
        $this->assertCount(1, $violations);
        // The violation is from the production class, not the test class.
        $this->assertStringContainsString('App\\Database\\QueryBuilder', $violations[0]->message);
        // The test file violation must be absent.
        $this->assertCount(0, $ruleViolationCollection->forRule('ruleset.HTTP'));
    }

    public function testAnalyserRulesetSkipPathsRulesetSuppressesViolationsForMatchingFilesWithPathBasedLayers(): void
    {
        $basePath = $this->makeTempProject([
            'src/HTTP/Request.php'          => <<<'PHP'
                <?php

                namespace App\HTTP;

                final class Request {}
                PHP,
            'src/Database/QueryBuilder.php' => <<<'PHP'
                <?php

                namespace App\Database;

                use App\HTTP\Request;

                final class QueryBuilder
                {
                    public function __construct(private Request $request) {}
                }
                PHP,
            'tests/HTTP/RequestTest.php'    => <<<'PHP'
                <?php

                namespace App\HTTP;

                use App\Database\QueryBuilder;

                final class RequestTest
                {
                    public function __construct(private QueryBuilder $db) {}
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('HTTP', ['src/HTTP/', 'tests/HTTP/'])
            ->layer('Database', 'src/Database/')
            ->ruleset([
                'HTTP'     => [], // Database NOT allowed
                'Database' => [], // HTTP NOT allowed
            ])
            ->skipPathsForRuleset(['*tests*']);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertTrue($ruleViolationCollection->hasViolations());
        $violations = $ruleViolationCollection->forRule('ruleset.Database');
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('App\\Database\\QueryBuilder', $violations[0]->message);
        $this->assertCount(0, $ruleViolationCollection->forRule('ruleset.HTTP'));
    }

    public function testAnalyserRulesetSkipPathsForRulesetDoesNotSuppressClassRules(): void
    {
        $basePath = $this->makeTempProject([
            'tests/HTTP/RequestTest.php'    => <<<'PHP'
                <?php

                namespace App\HTTP;

                use App\Database\QueryBuilder;

                class RequestTest
                {
                    public function __construct(private QueryBuilder $db) {}
                }
                PHP,
            'src/Database/QueryBuilder.php' => <<<'PHP'
                <?php

                namespace App\Database;

                final class QueryBuilder {}
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->layerPattern('Database', '/^App\\\\Database\\\\.*$/')
            ->ruleset([
                'HTTP' => [],
            ])
            ->skipPathsForRuleset(['*tests*'])
            ->rule('http.must_be_final', new MustBeFinalRule('HTTP'));

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/', 'tests/']);

        $this->assertCount(0, $ruleViolationCollection->forRule('ruleset.HTTP'));

        $violations = $ruleViolationCollection->forRule('http.must_be_final');
        $this->assertCount(1, $violations);
        $this->assertStringEndsWith('/tests/HTTP/RequestTest.php', $this->normalisePath($violations[0]->file));
    }

    public function testRulesetPlusLayerSyntaxExpandsAllowedLayers(): void
    {
        $basePath = $this->makeTempProject([
            'src/RESTful/Resource.php' => <<<'PHP'
                <?php

                namespace App\RESTful;

                use App\Format\JsonFormatter;
                use App\Validation\Validator;

                final class Resource
                {
                    public function __construct(
                        private JsonFormatter $formatter,
                        private Validator $validator,
                    ) {}
                }
                PHP,
        ]);

        // RESTful uses +API and +Controller to inherit their allowed layers.
        // API allows Format; Controller allows Validation — both should be permitted for RESTful.
        $architecture = Architecture::define()
            ->layerPattern('RESTful', '/^App\\\\RESTful\\\\.*$/')
            ->layerPattern('Format', '/^App\\\\Format\\\\.*$/')
            ->layerPattern('Validation', '/^App\\\\Validation\\\\.*$/')
            ->layerPattern('API', '/^App\\\\API\\\\.*$/')
            ->layerPattern('Controller', '/^App\\\\Controller\\\\.*$/')
            ->ruleset([
                'API'        => ['Format'],
                'Controller' => ['Validation'],
                'RESTful'    => ['+API', '+Controller'],
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testRulesetPlusLayerSyntaxIncludesTargetLayerAndItsDependents(): void
    {
        // +API expands to: API (the layer itself) + Format (what API can depend on)
        // +Controller expands to: Controller (the layer itself) + Validation (what Controller can depend on)
        // So RESTful is allowed to depend on: API, Format, Controller, Validation
        $basePath = $this->makeTempProject([
            'src/API/Handler.php'          => '<?php namespace App\API; final class Handler {}',
            'src/Format/JsonFormatter.php' => '<?php namespace App\Format; final class JsonFormatter {}',
            'src/Controller/Base.php'      => '<?php namespace App\Controller; final class Base {}',
            'src/Validation/Validator.php' => '<?php namespace App\Validation; final class Validator {}',
            'src/RESTful/Resource.php'     => <<<'PHP'
                <?php

                namespace App\RESTful;

                use App\API\Handler;
                use App\Format\JsonFormatter;
                use App\Controller\Base;
                use App\Validation\Validator;

                final class Resource
                {
                    public function __construct(
                        private Handler $handler,
                        private JsonFormatter $formatter,
                        private Base $base,
                        private Validator $validator,
                    ) {}
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('RESTful', '/^App\\\\RESTful\\\\.*$/')
            ->layerPattern('API', '/^App\\\\API\\\\.*$/')
            ->layerPattern('Format', '/^App\\\\Format\\\\.*$/')
            ->layerPattern('Controller', '/^App\\\\Controller\\\\.*$/')
            ->layerPattern('Validation', '/^App\\\\Validation\\\\.*$/')
            ->ruleset([
                'API'        => ['Format'],
                'Controller' => ['Validation'],
                'RESTful'    => ['+API', '+Controller'],
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testRulesetPlusLayerSyntaxStillViolatesDisallowedLayers(): void
    {
        $basePath = $this->makeTempProject([
            'src/RESTful/Resource.php' => <<<'PHP'
                <?php

                namespace App\RESTful;

                use App\Database\QueryBuilder;

                final class Resource
                {
                    public function __construct(private QueryBuilder $db) {}
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('RESTful', '/^App\\\\RESTful\\\\.*$/')
            ->layerPattern('Database', '/^App\\\\Database\\\\.*$/')
            ->layerPattern('API', '/^App\\\\API\\\\.*$/')
            ->ruleset([
                'API'     => ['Format'],
                'RESTful' => ['+API'], // Database not in API's allowed list
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertTrue($ruleViolationCollection->hasViolations());
        $violations = $ruleViolationCollection->forRule('ruleset.RESTful');
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('Database', $violations[0]->message);
    }

    public function testRulesetPlusLayerSyntaxIgnoresUnknownReference(): void
    {
        $basePath = $this->makeTempProject([
            'src/RESTful/Resource.php' => <<<'PHP'
                <?php

                namespace App\RESTful;

                use App\Database\QueryBuilder;

                final class Resource
                {
                    public function __construct(private QueryBuilder $db) {}
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('RESTful', '/^App\\\\RESTful\\\\.*$/')
            ->layerPattern('Database', '/^App\\\\Database\\\\.*$/')
            ->ruleset([
                'RESTful' => ['+NonExistentLayer'], // unknown layer expands to nothing
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $violations = $ruleViolationCollection->forRule('ruleset.RESTful');
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('Database', $violations[0]->message);
    }

    public function testRulesetPlusLayerSyntaxHandlesCircularReference(): void
    {
        $basePath = $this->makeTempProject([
            'src/A/ClassA.php' => <<<'PHP'
                <?php

                namespace App\A;

                use App\B\ClassB;
                use App\Database\QueryBuilder;

                final class ClassA
                {
                    public function __construct(
                        private ClassB $b,
                        private QueryBuilder $qb,
                    ) {}
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layerPattern('A', '/^App\\\\A\\\\.*$/')
            ->layerPattern('B', '/^App\\\\B\\\\.*$/')
            ->layerPattern('Database', '/^App\\\\Database\\\\.*$/')
            ->ruleset([
                'A' => ['+B'], // +B expands to: B (the layer itself)
                'B' => ['+A'], // circular: when expanding +A from inside B, A is guarded
            ]);

        // Must not hang or throw — circular refs are silently skipped.
        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        // +B includes B itself → A may depend on B: no violation for ClassB.
        // Database is not in the expanded allowed list → violation.
        $violations = $ruleViolationCollection->forRule('ruleset.A');
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('Database', $violations[0]->message);
    }

    public function testMayNotDependOnRuleViolationIsDetectedViaAnalyser(): void
    {
        $basePath = $this->makeTempProject([
            'src/Domain/Order.php'
        => '<?php
namespace App\Domain;
use App\Infrastructure\Persistence\OrderRepository;

class Order {
    public function __construct(private OrderRepository $repo) {}
}
',
            'src/Infrastructure/Persistence/OrderRepository.php'
        => '<?php namespace App\Infrastructure\Persistence; class OrderRepository {}
',
        ]);

        $architecture = Architecture::define()
            ->layer('Domain', 'src/Domain/')
            ->layer('Infrastructure', 'src/Infrastructure/')
            ->rule('domain.not_depend_infrastructure', new MayNotDependOnRule(from: 'Domain', to: 'Infrastructure'));

        $ruleViolationCollection = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential());

        $violations = $ruleViolationCollection->forRule('domain.not_depend_infrastructure');
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('Infrastructure', $violations[0]->message);
    }

    public function testMayNotDependOnRuleDetectsViolationWhenDependencyMatchesSecondaryLayer(): void
    {
        // AuthTokenStore must be scanned so it gets a ClassNode with layers
        // ['Support', 'Auth'] which is then read from the dependency ClassNode.
        $basePath = $this->makeTempProject([
            'src/HTTP/LoginController.php'   => <<<'PHP'
                <?php

                namespace App\HTTP;

                use App\Support\AuthTokenStore;

                final class LoginController
                {
                    public function __construct(private AuthTokenStore $store) {}
                }
                PHP,
            'src/Support/AuthTokenStore.php' => <<<'PHP'
                <?php

                namespace App\Support;

                final class AuthTokenStore {}
                PHP,
        ]);

        // AuthTokenStore matches both Support (primary, by namespace) and Auth (by class name).
        // The rule forbids Auth; only checking the primary Support layer misses the violation.
        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->layerPattern('Support', '/^App\\\\Support\\\\.*$/')
            ->layerPattern('Auth', '/Auth/')
            ->rule(
                'http.not_depend_auth',
                new MayNotDependOnRule(from: 'HTTP', to: 'Auth')
            );

        $ruleViolationCollection = (new Analyser($basePath))
            ->analyse($architecture, ['src/'], null, AnalyserOptions::sequential());

        $violations = $ruleViolationCollection->forRule('http.not_depend_auth');
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('Auth', $violations[0]->message);
    }

    public function testAnalyserRulesetAllowsUnscannedDependencyWhenFirstMatchedLayerIsAllowed(): void
    {
        $basePath = $this->makeTempProject([
            'src/HTTP/LoginController.php' => <<<'PHP'
                <?php

                namespace App\HTTP;

                use App\Support\AuthTokenStore;

                final class LoginController
                {
                    public function __construct(private AuthTokenStore $store) {}
                }
                PHP,
        ]);

        // AuthTokenStore matches both Support and Auth. No file is needed for AuthTokenStore:
        // the ruleset path resolves dependency layers directly from the class name via
        // layerPattern(), so the class does not need to be scanned. Support is allowed for
        // HTTP, so the dependency must be permitted even though Auth is not allowed.
        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/')
            ->layerPattern('Support', '/^App\\\\Support\\\\.*$/')
            ->layerPattern('Auth', '/Auth/')
            ->ruleset([
                'HTTP' => ['Support'],
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture, ['src/']);

        $this->assertCount(0, $ruleViolationCollection->forRule('ruleset.HTTP'));
    }

    public function testAnalyserRulesetAllowsUnscannedDependencyWhenSecondMatchedLayerIsAllowed(): void
    {
        $basePath = $this->makeTempProject([
            'src/Application/Foo.php' => <<<'PHP'
                <?php

                namespace App\Application;

                use Vendor\Shared\Service;

                final class Foo
                {
                    public function __construct(private Service $service) {}
                }
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('Application', 'src/Application/')
            ->layerPattern('Vendor', '/^Vendor\\\\/')
            ->layerPattern('Service', '/Service$/')
            ->ruleset([
                'Application' => ['Service'],
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertCount(0, $ruleViolationCollection->forRule('ruleset.Application'));
    }

    public function testAnalyserRulesetDetectsViolationForScannedDepWithRegexLayerInMixedConfig(): void
    {
        $basePath = $this->makeTempProject([
            'src/Validation/Validation.php'  => <<<'PHP'
                <?php

                namespace App\Validation;

                use App\View\RendererInterface;

                final class Validation
                {
                    public function __construct(private RendererInterface $view) {}
                }
                PHP,
            'src/View/RendererInterface.php' => <<<'PHP'
                <?php

                namespace App\View;

                interface RendererInterface {}
                PHP,
        ]);

        // RendererInterface is scanned and matches layerPattern 'View', but also lives under
        // the path-based Source catch-all. The violation must be reported against layer 'View'.
        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->layerPattern('Validation', '/^App\\\\Validation\\\\.*$/')
            ->layerPattern('View', '/^App\\\\View\\\\.*$/')
            ->ruleset([
                'Validation' => [], // View is NOT allowed
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertTrue($ruleViolationCollection->hasViolations());
        $violations = $ruleViolationCollection->forRule('ruleset.Validation');
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('View', $violations[0]->message);
    }

    public function testAnalyserRulesetDetectsSourceLayerViolationInMixedConfig(): void
    {
        $basePath = $this->makeTempProject([
            'src/Controller/HomeController.php' => <<<'PHP'
                <?php

                namespace App\Controller;

                use App\Util\Helper;

                final class HomeController
                {
                    public function __construct(private Helper $helper) {}
                }
                PHP,
            'src/Util/Helper.php'               => <<<'PHP'
                <?php

                namespace App\Util;

                final class Helper {}
                PHP,
        ]);

        // Helper lands only in the path-based Source layer; it is still a registered layer
        // and must be enforced by the ruleset just like any other layer.
        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->layer('Controller', 'src/Controller/')
            ->layerPattern('Vendor', '/^Vendor\\\\/')
            ->ruleset([
                'Controller' => [],
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertCount(1, $ruleViolationCollection->forRule('ruleset.Controller'));
    }

    public function testAnalyserRulesetSkipClassViolationSuppressesViolationForScannedDepInMixedConfig(): void
    {
        $basePath = $this->makeTempProject([
            'src/Validation/Validation.php'  => <<<'PHP'
                <?php

                namespace App\Validation;

                use App\View\RendererInterface;

                final class Validation
                {
                    public function __construct(private RendererInterface $view) {}
                }
                PHP,
            'src/View/RendererInterface.php' => <<<'PHP'
                <?php

                namespace App\View;

                interface RendererInterface {}
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->layerPattern('Validation', '/^App\\\\Validation\\\\.*$/')
            ->layerPattern('View', '/^App\\\\View\\\\.*$/')
            ->ruleset([
                'Validation' => [],
            ])
            ->skipClassViolation('App\\Validation\\Validation', ['App\\View\\RendererInterface']);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }

    public function testAnalyserRulesetDetectsPathBasedLayerViolationWhenLayerPatternsAlsoExist(): void
    {
        $basePath = $this->makeTempProject([
            'src/HTTP/Request.php'          => <<<'PHP'
                <?php

                namespace App\HTTP;

                use App\Database\QueryBuilder;

                final class Request
                {
                    public function __construct(private QueryBuilder $qb) {}
                }
                PHP,
            'src/Database/QueryBuilder.php' => <<<'PHP'
                <?php

                namespace App\Database;

                final class QueryBuilder {}
                PHP,
        ]);

        $architecture = Architecture::define()
            ->layer('HTTP', 'src/HTTP/')
            ->layer('Database', 'src/Database/')
            ->layerPattern('Vendor', '/^Vendor\\\\/')
            ->ruleset([
                'HTTP' => [],
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $violations = $ruleViolationCollection->forRule('ruleset.HTTP');
        $this->assertCount(1, $violations);
        $this->assertSame('App\\HTTP\\Request', $violations[0]->className);
    }

    public function testAnalyserRulesetTreatsPsr4ScanScopeDepAsExternalInMixedConfig(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json'                                  => '{"autoload":{"psr-4":{"CodeIgniter\\\\":"system/"}}}',
            'system/DataCaster/Exceptions/CastException.php' => <<<'PHP'
                <?php

                namespace CodeIgniter\DataCaster\Exceptions;

                use CodeIgniter\Exceptions\RuntimeException;

                final class CastException extends RuntimeException {}
                PHP,
            'system/Exceptions/RuntimeException.php'         => <<<'PHP'
                <?php

                namespace CodeIgniter\Exceptions;

                class RuntimeException extends \RuntimeException {}
                PHP,
        ]);

        // Source layer was defined with empty paths — it is a PSR4 scan-scope catch-all
        // (auto-expanded from composer.json). RuntimeException lands only in Source with no
        // regex match, so it must be treated as an unclassified external dependency (no violation).
        $architecture = Architecture::define()
            ->layer('Source', [])
            ->layerPattern('DataCaster', '/^CodeIgniter\\\\DataCaster\\\\.*$/')
            ->ruleset([
                'DataCaster' => [],
            ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertCount(0, $ruleViolationCollection->forRule('ruleset.DataCaster'));
    }

    public function testAnalyserRulesetIgnoresPsr4ScanScopeDependency(): void
    {
        $basePath = $this->makeTempProject([
            'composer.json'                                  => '{"autoload":{"psr-4":{"CodeIgniter\\\\":"system/"}}}',
            'system/DataCaster/Exceptions/CastException.php' => <<<'PHP'
            <?php

            namespace CodeIgniter\DataCaster\Exceptions;

            use CodeIgniter\Exceptions\RuntimeException;

            final class CastException extends RuntimeException {}
            PHP,
            'system/Exceptions/RuntimeException.php'         => <<<'PHP'
            <?php

            namespace CodeIgniter\Exceptions;

            class RuntimeException extends \RuntimeException {}
            PHP,
        ]);

        $architecture = Architecture::define()
        ->layer('Source', []) // scan scope, auto-expanded from composer
        ->layerPattern('DataCaster', '/^CodeIgniter\\\\DataCaster\\\\.*$/')
        ->ruleset([
            'DataCaster' => [],
        ]);

        $ruleViolationCollection = (new Analyser($basePath))->analyse($architecture);

        $this->assertCount(0, $ruleViolationCollection->forRule('ruleset.DataCaster'));
    }

    /** @param array<string, string> $files */
    private function makeTempProject(array $files): string
    {
        $basePath = $this->makeTemporaryDirectory('structarmed-analyser');

        foreach ($files as $file => $contents) {
            $path = $basePath . '/' . $file;

            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }

            file_put_contents($path, $contents);
        }

        return $basePath;
    }

    private function normalisePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
