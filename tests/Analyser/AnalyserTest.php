<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser;

use Boundwize\StructArmed\Analyser\Analyser;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\Preset;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Analyser::class)]
final class AnalyserTest extends TestCase
{
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->fixturesPath = dirname(__DIR__) . '/Fixtures/sample';
    }

    public function testAnalyserReturnsNoViolationsForValidCode(): void
    {
        $architecture = Architecture::define()
            ->layer('Domain', 'tests/Fixtures/sample/src/Domain/')
            ->layer('Application', 'tests/Fixtures/sample/src/Application/')
            ->layer('Infrastructure', 'tests/Fixtures/sample/src/Infrastructure/');

        $analyser   = new Analyser(dirname(__DIR__, 2));
        $violations = $analyser->analyse($architecture);

        // Order.php is a valid entity — should produce no layer violations
        $this->assertEmpty($violations->forLayer('Application'));
        $this->assertEmpty($violations->forLayer('Infrastructure'));
    }

    public function testAnalyserDetectsViolationsInBadCode(): void
    {
        $architecture = Architecture::define()
            ->layer('Domain', 'tests/Fixtures/sample/src/Domain/')
            ->layer('Application', 'tests/Fixtures/sample/src/Application/')
            ->layer('Infrastructure', 'tests/Fixtures/sample/src/Infrastructure/')
            ->withPreset(Preset::DDD());

        $analyser   = new Analyser(dirname(__DIR__, 2));
        $violations = $analyser->analyse($architecture);

        // BadOrderEntity.php uses DateTime and is not final — should have violations
        $this->assertTrue($violations->hasViolations());
    }

    public function testAnalyserReturnsEmptyCollectionForEmptyLayers(): void
    {
        $architecture = Architecture::define()
            ->layer('Domain', 'tests/Fixtures/sample/src/Domain/Events/');

        $analyser   = new Analyser(dirname(__DIR__, 2));
        $violations = $analyser->analyse($architecture);

        // Events directory is empty — no violations
        $this->assertFalse($violations->hasViolations());
    }

    public function testAnalyserSkipsNonExistentPaths(): void
    {
        $architecture = Architecture::define()
            ->layer('Domain', 'tests/Fixtures/sample/src/DoesNotExist/');

        $analyser = new Analyser(dirname(__DIR__, 2));

        // Should not throw — simply skip missing directories
        $violations = $analyser->analyse($architecture);

        $this->assertFalse($violations->hasViolations());
    }
}
