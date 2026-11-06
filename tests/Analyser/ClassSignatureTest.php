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
    public function testIsExposedFollowsVisibility(string $kind, string $visibility, bool $isExposed): void
    {
        $classSignature = new ClassSignature('App\Money', 'money', $kind, $visibility, 1);

        $this->assertSame($isExposed, $classSignature->isExposed());
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function exposures(): iterable
    {
        yield 'public parameter' => [ClassSignature::PARAM, 'public', true];
        yield 'protected method' => [ClassSignature::METHOD, 'protected', true];
        yield 'private property' => [ClassSignature::PROPERTY, 'private', false];
    }
}
