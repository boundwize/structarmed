<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\ClassSignature;
use Boundwize\StructArmed\Rule\Rules\Class_\ApiMayNotExposeInternalRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Rule\SignatureTypesAwareRuleInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_map;

#[CoversClass(ApiMayNotExposeInternalRule::class)]
final class ApiMayNotExposeInternalRuleTest extends TestCase
{
    public function testAsksTheAnalyserToCollectSignatures(): void
    {
        $this->assertInstanceOf(
            SignatureTypesAwareRuleInterface::class,
            new ApiMayNotExposeInternalRule(layer: 'Core')
        );
    }

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

    public function testReportsEveryExposedInternalTypeAtItsSignatureLine(): void
    {
        $apiMayNotExposeInternalRule = new ApiMayNotExposeInternalRule(layer: 'Core');
        $apiMayNotExposeInternalRule->injectClassNodeMap([
            'App\Money'    => $this->makeNode('App\Money', isApi: true),
            'App\Pool'     => $this->makeNode('App\Pool', isInternal: true),
            'App\Contract' => $this->makeNode('App\Contract', isInterface: true, isInternal: true),
        ]);

        $classNode = $this->makeNode(
            'App\Facade',
            signatures: [
                new ClassSignature('App\Money', 'run', ClassSignature::METHOD, 'public', 5),
                new ClassSignature('App\Pool', 'run', ClassSignature::METHOD, 'public', 7),
                new ClassSignature('Vendor\Unscanned', 'value', ClassSignature::PARAM, 'public', 9),
                new ClassSignature('App\Contract', 'value', ClassSignature::PROPERTY, 'protected', 11),
                new ClassSignature('App\Pool', 'value', ClassSignature::PARAM, 'protected', 13),
            ],
            isApi: true,
        );

        $violation = $apiMayNotExposeInternalRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(7, $violation->line);
        $this->assertSame(
            [
                [7, '@api Class [App\Facade] must not expose @internal Class [App\Pool] through method [run]'],
                [
                    11,
                    '@api Class [App\Facade] must not expose @internal Interface [App\Contract] '
                    . 'through property [value]',
                ],
                [13, '@api Class [App\Facade] must not expose @internal Class [App\Pool] through param [value]'],
            ],
            array_map(
                static fn (RuleViolation $ruleViolation): array => [$ruleViolation->line, $ruleViolation->message],
                $apiMayNotExposeInternalRule->evaluateAll($classNode)
            )
        );
    }

    public function testReportsConstructorParameterOfPrivatelyPromotedProperty(): void
    {
        $apiMayNotExposeInternalRule = new ApiMayNotExposeInternalRule(layer: 'Core');
        $apiMayNotExposeInternalRule->injectClassNodeMap([
            'App\Pool' => $this->makeNode('App\Pool', isInternal: true),
        ]);

        $violations = $apiMayNotExposeInternalRule->evaluateAll($this->makeNode(
            'App\Facade',
            signatures: [
                new ClassSignature('App\Pool', 'value', ClassSignature::PARAM, 'public', 6),
                new ClassSignature('App\Pool', 'value', ClassSignature::PROPERTY, 'private', 6),
            ],
            isApi: true,
        ));

        $this->assertCount(1, $violations);
        $this->assertSame(6, $violations[0]->line);
    }

    public function testReportsEachMemberExposingATypeOnTheSameLine(): void
    {
        $apiMayNotExposeInternalRule = new ApiMayNotExposeInternalRule(layer: 'Core');
        $apiMayNotExposeInternalRule->injectClassNodeMap([
            'App\Pool' => $this->makeNode('App\Pool', isInternal: true),
        ]);

        $violations = $apiMayNotExposeInternalRule->evaluateAll($this->makeNode(
            'App\Facade',
            signatures: [
                new ClassSignature('App\Pool', 'pool', ClassSignature::PARAM, 'public', 6),
                new ClassSignature('App\Pool', 'run', ClassSignature::METHOD, 'public', 6),
            ],
            isApi: true,
        ));

        $this->assertSame(
            [
                '@api Class [App\Facade] must not expose @internal Class [App\Pool] through param [pool]',
                '@api Class [App\Facade] must not expose @internal Class [App\Pool] through method [run]',
            ],
            array_map(static fn (RuleViolation $ruleViolation): string => $ruleViolation->message, $violations)
        );
    }

    public function testReportsATypeOnceWhenAMemberExposesItTwice(): void
    {
        $apiMayNotExposeInternalRule = new ApiMayNotExposeInternalRule(layer: 'Core');
        $apiMayNotExposeInternalRule->injectClassNodeMap([
            'App\Pool' => $this->makeNode('App\Pool', isInternal: true),
        ]);

        // A public promoted parameter is both a public parameter and a public property
        $violations = $apiMayNotExposeInternalRule->evaluateAll($this->makeNode(
            'App\Facade',
            signatures: [
                new ClassSignature('App\Pool', 'value', ClassSignature::PARAM, 'public', 6),
                new ClassSignature('app\pool', 'value', ClassSignature::PROPERTY, 'public', 6),
            ],
            isApi: true,
        ));

        $this->assertCount(1, $violations);
        $this->assertStringEndsWith('through param [value]', $violations[0]->message);
    }

    public function testFindsInternalTypeWrittenInAnotherCase(): void
    {
        $apiMayNotExposeInternalRule = new ApiMayNotExposeInternalRule(layer: 'Core');
        $apiMayNotExposeInternalRule->injectClassNodeMap([
            'App\Pool' => $this->makeNode('App\Pool', isInternal: true),
        ]);

        // Class names are case-insensitive: `function pool(): pool` is valid PHP
        $violation = $apiMayNotExposeInternalRule->evaluate($this->makeNode(
            'App\Facade',
            signatures: [new ClassSignature('app\pool', 'run', ClassSignature::METHOD, 'public', 5)],
            isApi: true,
        ));

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(
            '@api Class [App\Facade] must not expose @internal Class [App\Pool] through method [run]',
            $violation->message
        );
    }

    public function testPassesWhenInternalTypeIsPrivateOrOnlyAPlainDependency(): void
    {
        $apiMayNotExposeInternalRule = new ApiMayNotExposeInternalRule(layer: 'Core');
        $apiMayNotExposeInternalRule->injectClassNodeMap([
            'App\Pool' => $this->makeNode('App\Pool', isInternal: true),
        ]);

        // An @api class may use an @internal type behind its signature
        $this->assertNotInstanceOf(
            RuleViolation::class,
            $apiMayNotExposeInternalRule->evaluate($this->makeNode(
                'App\Facade',
                dependencies: ['App\Pool'],
                signatures: [
                    new ClassSignature('App\Pool', 'value', ClassSignature::PROPERTY, 'private', 5),
                    new ClassSignature('App\Pool', 'value', ClassSignature::PARAM, 'private', 7),
                    new ClassSignature('App\Pool', 'run', ClassSignature::METHOD, 'private', 7),
                ],
                isApi: true,
            ))
        );
    }

    /**
     * @param list<string>         $dependencies
     * @param list<ClassSignature> $signatures
     */
    private function makeNode(
        string $className,
        string $layer = 'Core',
        array $dependencies = [],
        array $signatures = [],
        bool $isInterface = false,
        bool $isTrait = false,
        bool $isEnum = false,
        bool $isApi = false,
        bool $isInternal = false,
    ): ClassNode {
        return new ClassNode(
            className:    $className,
            file:         '/fake.php',
            line:         1,
            layer:        $layer,
            extends:      null,
            isAbstract:   false,
            isFinal:      false,
            isInterface:  $isInterface,
            isReadonly:   false,
            isTrait:      $isTrait,
            dependencies: $dependencies,
            isEnum:       $isEnum,
            isApi:        $isApi,
            isInternal:   $isInternal,
            signatures:   $signatures,
        );
    }
}
