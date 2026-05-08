<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule;

use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Rule\RuleViolationCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuleViolation::class)]
#[CoversClass(RuleViolationCollection::class)]
final class RuleViolationTest extends TestCase
{
    public function testViolationFormatsItself(): void
    {
        $violation = $this->violation('first.rule', 'Domain');

        $this->assertSame(
            '[first.rule] Broken rule in /src/File.php:7',
            $violation->toString()
        );
        $this->assertSame([
            'rule'    => 'first.rule',
            'message' => 'Broken rule',
            'file'    => '/src/File.php',
            'line'    => 7,
            'class'   => 'App\\Domain\\File',
            'layer'   => 'Domain',
        ], $violation->toArray());
    }

    public function testCollectionFiltersAndSerializesViolations(): void
    {
        $collection = new RuleViolationCollection();
        $domain     = $this->violation('domain.rule', 'Domain');
        $app        = $this->violation('app.rule', 'Application');

        $collection->add($domain);
        $other = new RuleViolationCollection();
        $other->add($app);
        $collection->merge($other);

        $this->assertFalse($collection->isEmpty());
        $this->assertTrue($collection->hasViolations());
        $this->assertCount(2, $collection);
        $this->assertSame([$domain], $collection->forLayer('Domain'));
        $this->assertSame([$app], $collection->forRule('app.rule'));
        $this->assertSame($collection->toArray(), json_decode($collection->toJson(), true));
        $this->assertSame([$domain, $app], iterator_to_array($collection));
    }

    private function violation(string $ruleKey, string $layer): RuleViolation
    {
        return new RuleViolation(
            ruleKey: $ruleKey,
            message: 'Broken rule',
            file: '/src/File.php',
            line: 7,
            className: 'App\\Domain\\File',
            layer: $layer,
        );
    }
}
