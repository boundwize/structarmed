<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Class_\ApiMayNotBeInternalRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiMayNotBeInternalRule::class)]
final class ApiMayNotBeInternalRuleTest extends TestCase
{
    public function testAppliesOnlyToApiClassLikesInConfiguredLayer(): void
    {
        $apiMayNotBeInternalRule = new ApiMayNotBeInternalRule(layer: 'Core');

        $this->assertTrue($apiMayNotBeInternalRule->appliesTo($this->makeNode(isApi: true)));
        $this->assertFalse($apiMayNotBeInternalRule->appliesTo($this->makeNode(isInternal: true)));
        $this->assertFalse($apiMayNotBeInternalRule->appliesTo($this->makeNode(layer: 'Other', isApi: true)));
    }

    public function testViolatesWhenMarkedBothApiAndInternal(): void
    {
        $apiMayNotBeInternalRule = new ApiMayNotBeInternalRule(layer: 'Core');

        $violation = $apiMayNotBeInternalRule->evaluate(
            $this->makeNode(isInterface: true, isApi: true, isInternal: true)
        );

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(
            'Interface [App\Facade] must not be marked both @api and @internal',
            $violation->message
        );
    }

    public function testPassesWhenMarkedOnlyApi(): void
    {
        $apiMayNotBeInternalRule = new ApiMayNotBeInternalRule(layer: 'Core');

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $apiMayNotBeInternalRule->evaluate($this->makeNode(isApi: true))
        );
    }

    private function makeNode(
        string $layer = 'Core',
        bool $isInterface = false,
        bool $isApi = false,
        bool $isInternal = false,
    ): ClassNode {
        return new ClassNode(
            className:   'App\Facade',
            file:        '/fake.php',
            line:        1,
            layer:       $layer,
            extends:     null,
            isAbstract:  false,
            isFinal:     false,
            isInterface: $isInterface,
            isReadonly:  false,
            isApi:       $isApi,
            isInternal:  $isInternal,
        );
    }
}
