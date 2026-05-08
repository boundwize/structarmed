<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Report;

use Boundwize\StructArmed\Report\Reports\ConsoleReport;
use Boundwize\StructArmed\Report\Reports\JsonReport;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Rule\RuleViolationCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

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
        $collection = new RuleViolationCollection();
        $collection->add($this->violation());

        $report = (new ConsoleReport())->render($collection, 0.34);

        $this->assertStringContainsString('Found 1 violation(s):', $report);
        $this->assertStringContainsString('[rule.key]', $report);
        $this->assertStringContainsString('Layer: Domain', $report);
        $this->assertStringContainsString('1 violation(s) found', $report);
    }

    public function testJsonReportRendersPayload(): void
    {
        $collection = new RuleViolationCollection();
        $collection->add($this->violation());

        $json = (new JsonReport())->render($collection, 1.23);
        $data = json_decode($json, true);

        $this->assertIsArray($data);
        $this->assertIsArray($data['violations']);
        $this->assertIsArray($data['violations'][0]);
        $this->assertSame('rule.key', $data['violations'][0]['rule']);
        $this->assertSame(1, $data['total']);
        $this->assertFalse($data['passed']);
        $this->assertSame(1.23, $data['elapsed']);
    }

    private function violation(): RuleViolation
    {
        return new RuleViolation(
            ruleKey: 'rule.key',
            message: 'Something failed',
            file: '/src/Order.php',
            line: 10,
            className: 'App\\Domain\\Order',
            layer: 'Domain',
        );
    }
}
