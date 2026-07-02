<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser;

use Boundwize\StructArmed\Analyser\Analyser;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeFinalRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Analyser::class)]
final class AnalyserSkipPathsTest extends TestCase
{
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
