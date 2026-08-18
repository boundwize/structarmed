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
        $publicConstructor = new MethodNode('__construct', 'public', false, false, 1, 1, 3, isMagic: true);
        $publicDestructor  = new MethodNode('__destruct', 'public', false, false, 0, 1, 3, isMagic: true);
        $protectedMethod   = new MethodNode('handle', 'protected', true, false, 0, 1, 2);

        $this->assertTrue($publicConstructor->isPublic());
        $this->assertTrue($publicConstructor->isMagic);
        $this->assertTrue($publicConstructor->isConstructor());
        $this->assertFalse($publicConstructor->isDestructor());
        $this->assertTrue($publicDestructor->isDestructor());
        $this->assertTrue($publicDestructor->isMagic);
        $this->assertFalse($protectedMethod->isPublic());
        $this->assertFalse($protectedMethod->isMagic);
        $this->assertFalse($protectedMethod->isConstructor());
        $this->assertFalse($protectedMethod->isDestructor());
    }

    public function testConstructorAndDestructorDetectionIsCaseInsensitive(): void
    {
        $upperConstructor = new MethodNode('__CONSTRUCT', 'public', false, false, 0, 1, 3, isMagic: true);
        $mixedConstructor = new MethodNode('__Construct', 'public', false, false, 0, 1, 3, isMagic: true);
        $upperDestructor  = new MethodNode('__DESTRUCT', 'public', false, false, 0, 1, 3, isMagic: true);
        $mixedDestructor  = new MethodNode('__Destruct', 'public', false, false, 0, 1, 3, isMagic: true);

        $this->assertTrue($upperConstructor->isConstructor());
        $this->assertTrue($mixedConstructor->isConstructor());
        $this->assertFalse($upperConstructor->isDestructor());
        $this->assertTrue($upperDestructor->isDestructor());
        $this->assertTrue($mixedDestructor->isDestructor());
        $this->assertFalse($upperDestructor->isConstructor());
    }
}
