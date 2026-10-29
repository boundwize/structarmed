<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Cache;

use Boundwize\StructArmed\Cache\AnalysisCacheMetadataFactory;
use Boundwize\StructArmed\Cache\AnalysisResultCache;
use Boundwize\StructArmed\Cache\ConfigurationFingerprint;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function dirname;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function hash_file;
use function mkdir;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function strlen;
use function var_export;

use const PHP_BINARY;

#[CoversClass(AnalysisCacheMetadataFactory::class)]
#[CoversClass(AnalysisResultCache::class)]
#[CoversClass(ConfigurationFingerprint::class)]
final class ConfigurationCacheTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    /** @return iterable<string, array{string}> */
    public static function runners(): iterable
    {
        yield 'CLI sequential' => ['sequential'];
        yield 'CLI parallel' => ['parallel'];
        yield 'PHPUnit extension' => ['extension'];
    }

    #[DataProvider('runners')]
    public function testEvaluatedConfigurationInvalidatesNativeCaches(string $runner): void
    {
        $project  = $this->project();
        $rootHash = hash_file('xxh128', $project . '/structarmed.php');
        $this->settings($project, 'Allowed', 1);

        $this->assertSame(0, $this->runNative($project, $runner)[0]);
        $this->assertSame(1, $this->evaluations($project));
        $this->assertNotEmpty(glob($project . '/cache/analysis-nodes-*.json'));

        // A separate PHP process constructs fresh rules; the result cache must hit.
        $this->assertSame(0, $this->runNative($project, $runner)[0]);
        $this->assertSame(1, $this->evaluations($project));

        // The root and PHP source are unchanged, but the dependency changes layer.
        $this->settings($project, 'Forbidden', 1);
        [$exit, $output] = $this->runNative($project, $runner);
        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('must not depend on', $output);
        $this->assertSame(2, $this->evaluations($project));

        // Keep layer assignments and change only an ordinary custom rule option.
        $this->settings($project, 'Forbidden', 0);
        [$exit, $output] = $this->runNative($project, $runner);
        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('maximum allowed is 0', $output);
        $this->assertSame(3, $this->evaluations($project));

        $this->settings($project, 'Allowed', 1);
        $this->assertSame(0, $this->runNative($project, $runner)[0]);
        $this->assertSame(4, $this->evaluations($project));

        $this->assertSame($rootHash, hash_file('xxh128', $project . '/structarmed.php'));
    }

    public function testChangedConfiguredCallableInvalidatesTheWarmResultCache(): void
    {
        $project = $this->project();
        $this->settings($project, 'Allowed', 'strlen');
        $this->assertSame(0, $this->runNative($project, 'sequential')[0]);
        $this->assertSame(0, $this->runNative($project, 'sequential')[0]);
        $this->assertSame(1, $this->evaluations($project));

        $this->settings($project, 'Allowed', 'intval');
        [$exit, $output] = $this->runNative($project, 'sequential');
        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('maximum allowed is 0', $output);
        $this->assertSame(2, $this->evaluations($project));
    }

    public function testRootConfigEditsStillInvalidateTheWarmCache(): void
    {
        $project = $this->project();
        $this->settings($project, 'Allowed', 1);
        $this->assertSame(0, $this->runNative($project, 'sequential')[0]);
        $this->assertSame(0, $this->runNative($project, 'sequential')[0]);
        $this->assertSame(1, $this->evaluations($project));

        $config = file_get_contents($project . '/structarmed.php');
        $this->assertIsString($config);
        file_put_contents($project . '/structarmed.php', $config . "\n// Root configuration edited.\n");
        $this->assertSame(0, $this->runNative($project, 'sequential')[0]);
        $this->assertSame(2, $this->evaluations($project));
    }

    private function project(): string
    {
        $project = $this->makeTemporaryDirectory('structarmed-config-cache');
        mkdir($project . '/src');
        file_put_contents($project . '/composer.json', '{"autoload":{"psr-4":{"App\\\\":"src/"}}}');
        file_put_contents($project . '/src/Consumer.php', <<<'PHP_SOURCE'
<?php
namespace App;
final class Consumer
{
    public function __construct(private Dependency $dependency) {}
}
PHP_SOURCE);
        file_put_contents($project . '/src/Dependency.php', '<?php namespace App; final class Dependency {}');
        file_put_contents($project . '/structarmed.php', <<<'CONFIG'
<?php
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Rules\Layer\MayNotDependOnRule;
require_once __DIR__ . '/ProbeRule.php';
$settings = require __DIR__ . '/settings.php';
return Architecture::define()
    ->cacheDirectory('cache')
    ->layer('Source', 'src/Consumer.php')
    ->layer($settings['layer'], 'src/Dependency.php')
    ->rule('deny', new MayNotDependOnRule('Source', 'Forbidden'))
    ->rule('count', new ConfiguredCountRule(
        is_string($settings['max']) ? Closure::fromCallable($settings['max']) : $settings['max']
    ))
    ->rule('probe', new ProbeRule(__DIR__));
CONFIG);
        file_put_contents($project . '/ProbeRule.php', <<<'RULE'
<?php
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\ProjectRuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;
final readonly class ConfiguredCountRule implements \Boundwize\StructArmed\Rule\RuleInterface
{
    public function __construct(private int|Closure $maximum) {}
    public function appliesTo(\Boundwize\StructArmed\Analyser\ClassNode $node): bool
    {
        return $node->isInLayer('Source');
    }
    public function evaluate(\Boundwize\StructArmed\Analyser\ClassNode $node): ?RuleViolation
    {
        $maximum = $this->maximum instanceof Closure ? ($this->maximum)('0') : $this->maximum;
        return (new \Boundwize\StructArmed\Rule\Rules\Class_\MaxDependencyCountRule('Source', $maximum))
            ->evaluate($node);
    }
}
final class ProbeRule implements ProjectRuleInterface
{
    private Closure $service;
    public function __construct(private string $directory) { $this->service = static fn() => null; }
    public function evaluateProject(string $basePath, Architecture $architecture, array $skipPaths = []): ?RuleViolation
    {
        file_put_contents($this->directory . '/evaluations', '.', FILE_APPEND);
        return null;
    }
}
RULE);
        file_put_contents($project . '/extension.php', <<<'EXTENSION'
<?php
use Boundwize\StructArmed\Exception\ViolationsFoundException;
use Boundwize\StructArmed\PHPUnit\StructArmedExtension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
require $argv[1];
$reflection = new ReflectionClass(Configuration::class);
$configuration = $reflection->newInstanceWithoutConstructor();
$reflection->getProperty('noProgress')->setValue($configuration, true);
putenv('STRUCTARMED_DISABLED');
try {
    (new StructArmedExtension())->bootstrap($configuration, new Facade(), ParameterCollection::fromArray([
        'config' => __DIR__ . '/structarmed.php',
    ]));
} catch (ViolationsFoundException) {
    exit(1);
}
EXTENSION);

        return $project;
    }

    private function settings(string $project, string $layer, int|string $maximum): void
    {
        file_put_contents(
            $project . '/settings.php',
            '<?php return ' . var_export(['layer' => $layer, 'max' => $maximum], true) . ';'
        );
    }

    private function evaluations(string $project): int
    {
        $contents = file_get_contents($project . '/evaluations');
        $this->assertIsString($contents);

        return strlen($contents);
    }

    /**
     * @phpstan-impure Executes another PHP process and mutates its project cache.
     * @return array{int, string}
     */
    private function runNative(string $project, string $runner): array
    {
        $root    = dirname(__DIR__, 2);
        $command = $runner === 'extension'
            ? [PHP_BINARY, $project . '/extension.php', $root . '/vendor/autoload.php']
            : [PHP_BINARY, $root . '/bin/structarmed', 'analyse', '--no-progress'];

        if ($runner === 'sequential') {
            $command[] = '--disable-parallel';
        }

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $project);
        $this->assertIsResource($process);
        $output  = stream_get_contents($pipes[1]);
        $output .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }
}
