<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Report;

use Boundwize\StructArmed\Report\Reports\ConsoleReport;
use Boundwize\StructArmed\Report\Reports\JsonReport;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Rule\RuleViolationCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function json_decode;

use const PHP_FLOAT_EPSILON;

#[CoversClass(ConsoleReport::class)]
#[CoversClass(JsonReport::class)]
final class ReportTest extends TestCase
{
    public function testConsoleReportRendersPassingResult(): void
    {
        $report = (new ConsoleReport())->render(new RuleViolationCollection(), 0.12);

        $this->assertStringContainsString('StructArmed', $report);
        $this->assertStringContainsString('No violations found', $report);
    }

    public function testConsoleReportRendersViolationsWithLayer(): void
    {
        $ruleViolationCollection = new RuleViolationCollection();
        $ruleViolationCollection->add($this->violation());

        $report = (new ConsoleReport())->render($ruleViolationCollection, 0.34);

        $this->assertStringContainsString('Found 1 violation(s):', $report);
        $this->assertStringContainsString('[rule.key]', $report);
        $this->assertStringContainsString('Layer: Domain', $report);
        $this->assertStringContainsString('1 violation(s) found', $report);
    }

    public function testJsonReportEndsWithNewline(): void
    {
        $json = (new JsonReport())->render(new RuleViolationCollection(), 0.0);

        $this->assertStringEndsWith("\n", $json);
    }

    public function testJsonReportRendersPayload(): void
    {
        $ruleViolationCollection = new RuleViolationCollection();
        $ruleViolationCollection->add($this->violation());

        $json = (new JsonReport())->render($ruleViolationCollection, 1.23);
        $data = json_decode($json, true);

        $this->assertIsArray($data);
        $this->assertIsArray($data['violations']);
        $this->assertIsArray($data['violations'][0]);
        $this->assertSame('rule.key', $data['violations'][0]['rule']);
        $this->assertSame(1, $data['total']);
        $this->assertFalse($data['passed']);
        $this->assertEqualsWithDelta(1.23, $data['elapsed'], PHP_FLOAT_EPSILON);
    }

    private function violation(): RuleViolation
    {
        return new RuleViolation(
            message: 'Something failed',
            file: '/src/Order.php',
            line: 10,
            className: 'App\\Domain\\Order',
            layer: 'Domain',
            ruleKey: 'rule.key',
        );
    }
}
