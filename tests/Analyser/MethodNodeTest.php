<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser;

use Boundwize\StructArmed\Analyser\MethodNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MethodNode::class)]
final class MethodNodeTest extends TestCase
{
    public function testMethodHelpers(): void
    {
        $publicConstructor = new MethodNode('__construct', 'public', false, false, 1, 1, 3);
        $protectedMethod   = new MethodNode('handle', 'protected', true, false, 0, 1, 2);

        $this->assertTrue($publicConstructor->isPublic());
        $this->assertTrue($publicConstructor->isConstructor());
        $this->assertFalse($protectedMethod->isPublic());
        $this->assertFalse($protectedMethod->isConstructor());
    }
}
