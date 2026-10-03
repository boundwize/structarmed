<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule;

use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Rule\RuleViolationCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

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

    public function testRuleKeyDefaultsToEmptyString(): void
    {
        $ruleViolation = new RuleViolation(
            message: 'Broken rule',
            file: '/src/File.php',
            line: 7,
            className: 'App\\Domain\\File',
        );

        $this->assertSame('', $ruleViolation->ruleKey);
    }

    public function testFixableViolationSerializesFixableFlag(): void
    {
        $ruleViolation = new RuleViolation(
            message:   'Broken rule',
            file:      '/src/File.php',
            line:      7,
            className: 'App\\Domain\\File',
            ruleKey:   'first.rule',
            fixable:   true,
        );

        $this->assertSame([
            'rule'    => 'first.rule',
            'message' => 'Broken rule',
            'file'    => '/src/File.php',
            'line'    => 7,
            'class'   => 'App\\Domain\\File',
            'layer'   => null,
            'fixable' => true,
        ], $ruleViolation->toArray());
    }

    public function testViolationSerializesFunctionNameWhenPresent(): void
    {
        $ruleViolation = new RuleViolation(
            message:      'Broken rule',
            file:         '/src/helpers.php',
            line:         7,
            className:    'App\\Support\\format',
            ruleKey:      'first.rule',
            functionName: 'App\\Support\\format',
        );

        $this->assertSame([
            'rule'     => 'first.rule',
            'message'  => 'Broken rule',
            'file'     => '/src/helpers.php',
            'line'     => 7,
            'class'    => 'App\\Support\\format',
            'layer'    => null,
            'function' => 'App\\Support\\format',
        ], $ruleViolation->toArray());
    }

    public function testNonFixableViolationDoesNotSerializeFixableFlag(): void
    {
        $this->assertArrayNotHasKey('fixable', $this->violation('first.rule', 'Domain')->toArray());
    }

    public function testViolationSerializesMethodNameWhenPresent(): void
    {
        $ruleViolation = new RuleViolation(
            message:    'Broken rule',
            file:       '/src/File.php',
            line:       7,
            className:  'App\\Domain\\File',
            methodName: 'save',
        );

        $this->assertSame('save', $ruleViolation->toArray()['method']);
    }

    public function testViolationSerializesConstantNameWhenPresent(): void
    {
        $ruleViolation = new RuleViolation(
            message:      'Broken rule',
            file:         '/src/File.php',
            line:         7,
            className:    'App\\Domain\\File',
            constantName: 'VERSION',
        );

        $this->assertSame('VERSION', $ruleViolation->toArray()['constant']);
    }

    public function testViolationSerializesPropertyNameWhenPresent(): void
    {
        $ruleViolation = new RuleViolation(
            message:      'Broken rule',
            file:         '/src/File.php',
            line:         7,
            className:    'App\\Domain\\File',
            propertyName: 'status',
        );

        $this->assertSame('status', $ruleViolation->toArray()['property']);
    }

    public function testViolationSerializesNumericLiteralWhenPresent(): void
    {
        $ruleViolation = new RuleViolation(
            message:        'Broken rule',
            file:           '/src/File.php',
            line:           7,
            className:      '',
            numericLiteral: '10000',
        );

        $this->assertSame('10000', $ruleViolation->toArray()['numericLiteral']);
    }

    public function testCollectionFiltersAndSerializesViolations(): void
    {
        $collection    = new RuleViolationCollection();
        $ruleViolation = $this->violation('domain.rule', 'Domain');
        $app           = $this->violation('app.rule', 'Application');

        $collection->add($ruleViolation);
        $other = new RuleViolationCollection();
        $other->add($app);

        $collection->merge($other);

        $this->assertFalse($collection->isEmpty());
        $this->assertTrue($collection->hasViolations());
        $this->assertCount(2, $collection);
        $this->assertSame([$app], $collection->forRule('app.rule'));
        $this->assertSame([$ruleViolation, $app], iterator_to_array($collection));
    }

    private function violation(string $ruleKey, string $layer): RuleViolation
    {
        return new RuleViolation(
            message: 'Broken rule',
            file: '/src/File.php',
            line: 7,
            className: 'App\\Domain\\File',
            layer: $layer,
            ruleKey: $ruleKey,
        );
    }
}
