<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Cli;

use Boundwize\StructArmed\Cli\AnalyseCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(AnalyseCommand::class)]
final class AnalyseCommandTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function fixedViolationMessageProvider(): iterable
    {
        yield 'singular' => [1, '1 violation has been fixed.'];
        yield 'plural'   => [2, '2 violations have been fixed.'];
    }

    #[DataProvider('fixedViolationMessageProvider')]
    public function testFormatsFixedViolationMessage(int $fixedCount, string $expectedMessage): void
    {
        $reflectionMethod = new ReflectionMethod(AnalyseCommand::class, 'fixedViolationMessage');

        $message = $reflectionMethod->invoke(new AnalyseCommand(), $fixedCount);

        $this->assertIsString($message);
        $this->assertStringContainsString($expectedMessage, $message);
    }
}
