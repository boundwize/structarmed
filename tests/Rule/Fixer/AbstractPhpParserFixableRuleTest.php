<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Fixer;

use Boundwize\StructArmed\Rule\Fixer\AbstractPhpParserFixableRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractPhpParserFixableRule::class)]
final class AbstractPhpParserFixableRuleTest extends TestCase
{
    public function testFixReturnsFalseWhenNoVisitorIsAvailable(): void
    {
        $phpParserFixableRule = new readonly class extends AbstractPhpParserFixableRule {
        };

        $this->assertFalse($phpParserFixableRule->fix(new RuleViolation(
            message:   'Missing fixer visitor',
            file:      '/missing.php',
            line:      1,
            className: 'Order',
        )));
    }
}
