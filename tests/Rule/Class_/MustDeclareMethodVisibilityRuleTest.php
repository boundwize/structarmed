<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\MethodNode;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\AbstractPhpParserFixableRule;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\ClassMethod\AddPublicMethodVisibilityVisitor;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\PhpParserFixerProcessor;
use Boundwize\StructArmed\Rule\Rules\Class_\MustDeclareMethodVisibilityRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(MustDeclareMethodVisibilityRule::class)]
#[CoversClass(AbstractPhpParserFixableRule::class)]
#[CoversClass(PhpParserFixerProcessor::class)]
#[CoversClass(AddPublicMethodVisibilityVisitor::class)]
final class MustDeclareMethodVisibilityRuleTest extends TestCase
{
    public function testAppliesOnlyToConfiguredLayer(): void
    {
        $mustDeclareMethodVisibilityRule = new MustDeclareMethodVisibilityRule('Source');

        $this->assertTrue($mustDeclareMethodVisibilityRule->appliesTo($this->makeNode([], 'Source')));
        $this->assertFalse($mustDeclareMethodVisibilityRule->appliesTo($this->makeNode([], 'Other')));
    }

    public function testPassesWhenVisibilityIsExplicit(): void
    {
        $mustDeclareMethodVisibilityRule = new MustDeclareMethodVisibilityRule('Source');

        $this->assertSame(
            [],
            $mustDeclareMethodVisibilityRule->evaluateAll($this->makeNode([
                new MethodNode('save', 'public', true, false, 0, 1, 3, hasExplicitVisibility: true),
            ]))
        );
    }

    public function testViolatesWhenVisibilityIsImplicit(): void
    {
        $mustDeclareMethodVisibilityRule = new MustDeclareMethodVisibilityRule('Source');

        $violations = $mustDeclareMethodVisibilityRule->evaluateAll($this->makeNode([
            new MethodNode('save', 'public', true, false, 0, 1, 3, hasExplicitVisibility: false, line: 5),
        ]));

        $this->assertCount(1, $violations);
        $this->assertInstanceOf(RuleViolation::class, $violations[0]);
        $this->assertStringContainsString('save', $violations[0]->message);
        $this->assertSame(5, $violations[0]->line);
        $this->assertSame('save', $violations[0]->methodName);
    }

    public function testEvaluateReturnsFirstViolation(): void
    {
        $mustDeclareMethodVisibilityRule = new MustDeclareMethodVisibilityRule('Source');

        $violation = $mustDeclareMethodVisibilityRule->evaluate($this->makeNode([
            new MethodNode('save', 'public', true, false, 0, 1, 3, hasExplicitVisibility: false),
        ]));

        $this->assertInstanceOf(RuleViolation::class, $violation);
    }

    public function testFixAddsPublicVisibilityToImplicitMethod(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'structarmed-method-');
        $this->assertIsString($file);

        file_put_contents($file, <<<'PHP'
<?php

class Order
{
    static function save(): void
    {
    }
}
PHP);

        try {
            $mustDeclareMethodVisibilityRule = new MustDeclareMethodVisibilityRule('Source');

            $this->assertTrue($mustDeclareMethodVisibilityRule->fix(new RuleViolation(
                message:   'Method [Order::save()] must declare an explicit visibility (public, protected, or private)',
                file:      $file,
                line:      5,
                className: 'Order',
                methodName: 'save',
            )));

            $this->assertStringContainsString(
                '    public static function save(): void',
                (string) file_get_contents($file)
            );
        } finally {
            unlink($file);
        }
    }

    /**
     * @param list<MethodNode> $methods
     */
    private function makeNode(array $methods, string $layer = 'Source'): ClassNode
    {
        return new ClassNode(
            className:  'App\\Order',
            file:       '/fake.php',
            line:       1,
            layer:      $layer,
            extends:    null,
            isAbstract: false,
            isFinal:    false,
            isInterface: false,
            isReadonly: false,
            methods:    $methods,
        );
    }
}
