<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Class_\ApiMayNotExposeInternalRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_map;

#[CoversClass(ApiMayNotExposeInternalRule::class)]
final class ApiMayNotExposeInternalRuleTest extends TestCase
{
    public function testAppliesOnlyToApiClassLikesInConfiguredLayer(): void
    {
        $apiMayNotExposeInternalRule = new ApiMayNotExposeInternalRule(layer: 'Core');

        $this->assertTrue($apiMayNotExposeInternalRule->appliesTo($this->makeNode('App\Facade', isApi: true)));
        $this->assertTrue(
            $apiMayNotExposeInternalRule->appliesTo($this->makeNode('App\Facade', isInterface: true, isApi: true))
        );
        $this->assertTrue(
            $apiMayNotExposeInternalRule->appliesTo($this->makeNode('App\Facade', isTrait: true, isApi: true))
        );
        $this->assertTrue(
            $apiMayNotExposeInternalRule->appliesTo($this->makeNode('App\Facade', isEnum: true, isApi: true))
        );
        $this->assertFalse($apiMayNotExposeInternalRule->appliesTo($this->makeNode('App\Facade')));
        $this->assertFalse(
            $apiMayNotExposeInternalRule->appliesTo($this->makeNode('App\Facade', layer: 'Other', isApi: true))
        );
    }

    public function testReportsEveryInternalTypeInSignature(): void
    {
        $apiMayNotExposeInternalRule = new ApiMayNotExposeInternalRule(layer: 'Core');
        $apiMayNotExposeInternalRule->injectClassNodeMap([
            'App\Money'    => $this->makeNode('App\Money', isApi: true),
            'App\Pool'     => $this->makeNode('App\Pool', isInternal: true),
            'App\Contract' => $this->makeNode('App\Contract', isInterface: true, isInternal: true),
        ]);

        $classNode = $this->makeNode(
            'App\Facade',
            signatureTypes: ['App\Money', 'App\Pool', 'Vendor\Unscanned', 'App\Contract'],
            isApi: true,
        );

        $violation = $apiMayNotExposeInternalRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(
            '@api Class [App\Facade] must not expose @internal Class [App\Pool] in its signature',
            $violation->message
        );
        $this->assertSame(
            [
                '@api Class [App\Facade] must not expose @internal Class [App\Pool] in its signature',
                '@api Class [App\Facade] must not expose @internal Interface [App\Contract] in its signature',
            ],
            array_map(
                static fn (RuleViolation $ruleViolation): string => $ruleViolation->message,
                $apiMayNotExposeInternalRule->evaluateAll($classNode)
            )
        );
    }

    public function testFindsInternalTypeWrittenInAnotherCase(): void
    {
        $apiMayNotExposeInternalRule = new ApiMayNotExposeInternalRule(layer: 'Core');
        $apiMayNotExposeInternalRule->injectClassNodeMap([
            'App\Pool' => $this->makeNode('App\Pool', isInternal: true),
        ]);

        // Class names are case-insensitive: `function pool(): pool` is valid PHP
        $violation = $apiMayNotExposeInternalRule->evaluate(
            $this->makeNode('App\Facade', signatureTypes: ['app\pool'], isApi: true)
        );

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(
            '@api Class [App\Facade] must not expose @internal Class [App\Pool] in its signature',
            $violation->message
        );
    }

    public function testPassesWhenInternalTypeIsOnlyAPlainDependency(): void
    {
        $apiMayNotExposeInternalRule = new ApiMayNotExposeInternalRule(layer: 'Core');
        $apiMayNotExposeInternalRule->injectClassNodeMap([
            'App\Pool' => $this->makeNode('App\Pool', isInternal: true),
        ]);

        // An @api class may use an @internal type behind its signature
        $this->assertNotInstanceOf(
            RuleViolation::class,
            $apiMayNotExposeInternalRule->evaluate(
                $this->makeNode('App\Facade', dependencies: ['App\Pool'], isApi: true)
            )
        );
    }

    /**
     * @param list<string> $dependencies
     * @param list<string> $signatureTypes
     */
    private function makeNode(
        string $className,
        string $layer = 'Core',
        array $dependencies = [],
        array $signatureTypes = [],
        bool $isInterface = false,
        bool $isTrait = false,
        bool $isEnum = false,
        bool $isApi = false,
        bool $isInternal = false,
    ): ClassNode {
        return new ClassNode(
            className:      $className,
            file:           '/fake.php',
            line:           1,
            layer:          $layer,
            extends:        null,
            isAbstract:     false,
            isFinal:        false,
            isInterface:    $isInterface,
            isReadonly:     false,
            isTrait:        $isTrait,
            dependencies:   $dependencies,
            isEnum:         $isEnum,
            isApi:          $isApi,
            isInternal:     $isInternal,
            signatureTypes: $signatureTypes,
        );
    }
}
