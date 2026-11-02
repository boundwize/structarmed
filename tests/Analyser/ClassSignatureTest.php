<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser;

use Boundwize\StructArmed\Analyser\ClassSignature;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClassSignature::class)]
final class ClassSignatureTest extends TestCase
{
    #[DataProvider('exposures')]
    public function testIsExposedFollowsVisibilityFlag(int $modifiers, bool $isExposed): void
    {
        $classSignature = new ClassSignature('App\Money', $modifiers, 1);

        $this->assertSame($isExposed, $classSignature->isExposed());
    }

    /** @return iterable<string, array{int, bool}> */
    public static function exposures(): iterable
    {
        yield 'public parameter' => [ClassSignature::PUBLIC | ClassSignature::PARAMETER, true];
        yield 'protected return type' => [ClassSignature::PROTECTED | ClassSignature::RETURN_TYPE, true];
        yield 'private property' => [ClassSignature::PRIVATE | ClassSignature::PROPERTY, false];
    }
}
