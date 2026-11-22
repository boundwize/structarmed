<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Report;

use Boundwize\StructArmed\Report\WarningLine;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function getenv;
use function putenv;

#[CoversClass(WarningLine::class)]
final class WarningLineTest extends TestCase
{
    public function testRenderColorsOnlyTheLabelWhenColorsAreSupported(): void
    {
        $previousNoColor    = getenv('NO_COLOR');
        $previousForceColor = getenv('FORCE_COLOR');

        try {
            putenv('NO_COLOR');
            putenv('FORCE_COLOR=1');
            $colored = WarningLine::render('Something needs attention.');

            putenv('NO_COLOR=1');
            $plain = WarningLine::render('Something needs attention.');
        } finally {
            putenv($previousNoColor === false ? 'NO_COLOR' : 'NO_COLOR=' . $previousNoColor);
            putenv($previousForceColor === false ? 'FORCE_COLOR' : 'FORCE_COLOR=' . $previousForceColor);
        }

        $this->assertSame("\033[33mWarning:\033[0m Something needs attention.", $colored);
        $this->assertSame('Warning: Something needs attention.', $plain);
    }
}
