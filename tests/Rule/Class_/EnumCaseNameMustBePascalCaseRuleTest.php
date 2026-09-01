<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\EnumCaseNode;
use Boundwize\StructArmed\Rule\Rules\Class_\EnumCaseNameMustBePascalCaseRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnumCaseNode::class)]
#[CoversClass(EnumCaseNameMustBePascalCaseRule::class)]
final class EnumCaseNameMustBePascalCaseRuleTest extends TestCase
{
    public function testAppliesOnlyToEnumsInConfiguredLayer(): void
    {
        $enumCaseNameMustBePascalCaseRule = new EnumCaseNameMustBePascalCaseRule('Source');

        $this->assertTrue($enumCaseNameMustBePascalCaseRule->appliesTo($this->makeNode([], 'Source')));
        $this->assertFalse($enumCaseNameMustBePascalCaseRule->appliesTo($this->makeNode([], 'Other')));
        $this->assertFalse($enumCaseNameMustBePascalCaseRule->appliesTo(
            $this->makeNode([], 'Source', isEnum: false)
        ));
    }

    public function testEvaluateReturnsFirstViolation(): void
    {
        $enumCaseNameMustBePascalCaseRule = new EnumCaseNameMustBePascalCaseRule('Source');

        $violation = $enumCaseNameMustBePascalCaseRule->evaluate(
            $this->makeNode([new EnumCaseNode('draft'), new EnumCaseNode('published')])
        );

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(1, $violation->line);
    }

    #[DataProvider('pascalCaseNameProvider')]
    public function testPassesPascalCaseNames(string $name): void
    {
        $enumCaseNameMustBePascalCaseRule = new EnumCaseNameMustBePascalCaseRule('Source');

        $this->assertSame(
            [],
            $enumCaseNameMustBePascalCaseRule->evaluateAll(
                $this->makeNode([new EnumCaseNode($name)])
            )
        );
    }

    /** @return iterable<string, array{string}> */
    public static function pascalCaseNameProvider(): iterable
    {
        yield 'single word'  => ['Draft'];
        yield 'multi word'   => ['PendingReview'];
        yield 'with digits'  => ['Http404'];
        yield 'abbreviation' => ['XmlExport'];
    }

    #[DataProvider('nonPascalCaseNameProvider')]
    public function testViolatesNonPascalCaseNames(string $name): void
    {
        $enumCaseNameMustBePascalCaseRule = new EnumCaseNameMustBePascalCaseRule('Source');

        $violations = $enumCaseNameMustBePascalCaseRule->evaluateAll(
            $this->makeNode([new EnumCaseNode(name: $name, line: 7)])
        );

        $this->assertCount(1, $violations);
        $this->assertInstanceOf(RuleViolation::class, $violations[0]);
        $this->assertSame(7, $violations[0]->line);
        $this->assertSame(
            'Enum case [App\\Status::' . $name . '] must be declared in PascalCase',
            $violations[0]->message
        );
    }

    /** @return iterable<string, array{string}> */
    public static function nonPascalCaseNameProvider(): iterable
    {
        yield 'camelCase'   => ['pendingReview'];
        yield 'lower case'  => ['draft'];
        yield 'UPPER_CASE'  => ['PENDING_REVIEW'];
        yield 'snake_case'  => ['pending_review'];
        yield 'underscored' => ['Pending_Review'];
    }

    /**
     * @param list<EnumCaseNode> $enumCases
     */
    private function makeNode(
        array $enumCases,
        string $layer = 'Source',
        bool $isEnum = true,
    ): ClassNode {
        return new ClassNode(
            className: 'App\\Status',
            file: '/fake.php',
            line: 1,
            layer: $layer,
            extends: null,
            isAbstract: false,
            isFinal: false,
            isInterface: false,
            isReadonly: false,
            isEnum: $isEnum,
            enumCases: $enumCases,
        );
    }
}
