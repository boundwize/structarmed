<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Class_\ApiMayNotExtendInternalRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiMayNotExtendInternalRule::class)]
final class ApiMayNotExtendInternalRuleTest extends TestCase
{
    public function testAppliesOnlyToApiClassesAndInterfacesInConfiguredLayer(): void
    {
        $apiMayNotExtendInternalRule = new ApiMayNotExtendInternalRule(layer: 'Core');

        $this->assertTrue($apiMayNotExtendInternalRule->appliesTo($this->makeNode('App\Facade', isApi: true)));
        $this->assertTrue(
            $apiMayNotExtendInternalRule->appliesTo($this->makeNode('App\Facade', isInterface: true, isApi: true))
        );
        $this->assertFalse(
            $apiMayNotExtendInternalRule->appliesTo($this->makeNode('App\Facade', isTrait: true, isApi: true))
        );
        $this->assertFalse(
            $apiMayNotExtendInternalRule->appliesTo($this->makeNode('App\Facade', isEnum: true, isApi: true))
        );
        $this->assertFalse($apiMayNotExtendInternalRule->appliesTo($this->makeNode('App\Facade')));
        $this->assertFalse(
            $apiMayNotExtendInternalRule->appliesTo($this->makeNode('App\Facade', layer: 'Other', isApi: true))
        );
    }

    public function testViolatesWhenClassExtendsInternalAncestor(): void
    {
        $apiMayNotExtendInternalRule = new ApiMayNotExtendInternalRule(layer: 'Core');
        $apiMayNotExtendInternalRule->injectClassNodeMap([
            'App\BaseFacade'     => $this->makeNode('App\BaseFacade'),
            'App\InternalFacade' => $this->makeNode('App\InternalFacade', isInternal: true),
        ]);

        $violation = $apiMayNotExtendInternalRule->evaluate(
            $this->makeNode('App\Facade', parentClasses: ['App\BaseFacade', 'App\InternalFacade'], isApi: true)
        );

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(
            '@api Class [App\Facade] must not extend @internal Class [App\InternalFacade]',
            $violation->message
        );
    }

    public function testViolatesWhenInterfaceExtendsInternalInterface(): void
    {
        $apiMayNotExtendInternalRule = new ApiMayNotExtendInternalRule(layer: 'Core');
        $apiMayNotExtendInternalRule->injectClassNodeMap([
            'App\Public'   => $this->makeNode('App\Public', isInterface: true),
            'App\Internal' => $this->makeNode('App\Internal', isInterface: true, isInternal: true),
        ]);

        $violation = $apiMayNotExtendInternalRule->evaluate($this->makeNode(
            'App\Contract',
            parentInterfaces: ['App\Public', 'App\Unscanned', 'App\Internal'],
            isInterface: true,
            isApi: true,
        ));

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(
            '@api Interface [App\Contract] must not extend @internal Interface [App\Internal]',
            $violation->message
        );
    }

    public function testPassesWhenNoParentClassIsInternalOrScanned(): void
    {
        $apiMayNotExtendInternalRule = new ApiMayNotExtendInternalRule(layer: 'Core');
        $apiMayNotExtendInternalRule->injectClassNodeMap([
            'App\BaseFacade'       => $this->makeNode('App\BaseFacade', isApi: true),
            'App\InternalContract' => $this->makeNode('App\InternalContract', isInterface: true, isInternal: true),
        ]);

        // A class implements interfaces rather than extending them
        $this->assertNotInstanceOf(
            RuleViolation::class,
            $apiMayNotExtendInternalRule->evaluate($this->makeNode(
                'App\Facade',
                parentClasses: ['App\BaseFacade', 'Vendor\Base'],
                parentInterfaces: ['App\InternalContract'],
                isApi: true,
            ))
        );
    }

    /**
     * @param list<string> $parentClasses
     * @param list<string> $parentInterfaces
     */
    private function makeNode(
        string $className,
        string $layer = 'Core',
        array $parentClasses = [],
        array $parentInterfaces = [],
        bool $isInterface = false,
        bool $isTrait = false,
        bool $isEnum = false,
        bool $isApi = false,
        bool $isInternal = false,
    ): ClassNode {
        return new ClassNode(
            className:        $className,
            file:             '/fake.php',
            line:             1,
            layer:            $layer,
            extends:          $parentClasses[0] ?? null,
            isAbstract:       false,
            isFinal:          false,
            isInterface:      $isInterface,
            isReadonly:       false,
            isTrait:          $isTrait,
            isEnum:           $isEnum,
            parentClasses:    $parentClasses,
            parentInterfaces: $parentInterfaces,
            isApi:            $isApi,
            isInternal:       $isInternal,
        );
    }
}
