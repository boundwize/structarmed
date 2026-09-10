<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser;

use Boundwize\StructArmed\Analyser\Analyser;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\File\SkipPathMatcher;
use Boundwize\StructArmed\Preset\Preset;
use Boundwize\StructArmed\Preset\Presets\Psr4Preset;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeFinalRule;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function mkdir;

#[CoversClass(Analyser::class)]
#[CoversClass(SkipPathMatcher::class)]
final class AnalyserSkipPathsTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testPsr4RuleSpecificDirectoryGlobSkipsDescendants(): void
    {
        $basePath = $this->makeTemporaryDirectory('structarmed-psr4-skips');
        file_put_contents($basePath . '/composer.json', '{"autoload-dev":{"psr-4":{"App\\\\":"tests/"}}}');

        foreach (['functional/Core/Nested', 'unit', 'integration', 'functional/CoreExtra'] as $directory) {
            mkdir($basePath . '/tests/' . $directory, 0777, true);
            file_put_contents($basePath . '/tests/' . $directory . '/Wrong.php', '<?php class Mismatch {}');
        }

        $architecture = Architecture::define()
            ->skip([
                Psr4Preset::CLASSES_MUST_MATCH_COMPOSER => [
                    'tests/**/Core',
                    'tests/unit',
                    'tests/integration',
                ],
            ])
            ->withPreset(Preset::PSR4());

        $violations = (new Analyser($basePath))->analyse($architecture)
            ->forRule(Psr4Preset::CLASSES_MUST_MATCH_COMPOSER);

        $this->assertCount(1, $violations);
        $this->assertStringEndsWith('/tests/functional/CoreExtra/Wrong.php', $violations[0]->file);
    }

    public function testAnalyserComposesGlobalAndRuleSpecificSkipsForClassRules(): void
    {
        $architecture = Architecture::define()
            ->layer('Source', 'path-that-does-not-exist/')
            ->skip([
                'vendor/',
                'source.must_be_final' => ['legacy/'],
            ])
            ->rule('source.must_be_final', new MustBeFinalRule('Source'));

        $ruleViolationCollection = (new Analyser(__DIR__))->analyse($architecture);

        $this->assertFalse($ruleViolationCollection->hasViolations());
    }
}
