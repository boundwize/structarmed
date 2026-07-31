<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Fixer\PhpParser\Property;

use Boundwize\StructArmed\Rule\Fixer\PhpParser\Property\AddPublicPropertyVisibilityVisitor;
use PhpParser\Modifiers;
use PhpParser\Node\Expr\Error;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AddPublicPropertyVisibilityVisitor::class)]
final class AddPublicPropertyVisibilityVisitorTest extends TestCase
{
    public function testAddsPublicVisibilityToMatchingNamespacedProperty(): void
    {
        $property                           = new Property(Modifiers::STATIC, [new PropertyItem('status')]);
        $class                              = new Class_('Order', ['stmts' => [$property]]);
        $addPublicPropertyVisibilityVisitor = new AddPublicPropertyVisibilityVisitor('App\\Order', 'status');
        $class->namespacedName              = new Name('App\\Order');

        (new NodeTraverser($addPublicPropertyVisibilityVisitor))->traverse([$class]);

        $this->assertSame(Modifiers::PUBLIC | Modifiers::STATIC, $property->flags);
    }

    public function testDoesNotChangeAlreadyVisibleProperty(): void
    {
        $property                           = new Property(Modifiers::PUBLIC, [new PropertyItem('status')]);
        $class                              = new Class_('Order', ['stmts' => [$property]]);
        $addPublicPropertyVisibilityVisitor = new AddPublicPropertyVisibilityVisitor('Order', 'status');
        $class->namespacedName              = new Name('Order');

        (new NodeTraverser($addPublicPropertyVisibilityVisitor))->traverse([$class]);

        $this->assertSame(Modifiers::PUBLIC, $property->flags);
    }

    public function testDoesNotChangePropertyInDifferentClass(): void
    {
        $property                           = new Property(0, [new PropertyItem('status')]);
        $class                              = new Class_('Order', ['stmts' => [$property]]);
        $addPublicPropertyVisibilityVisitor = new AddPublicPropertyVisibilityVisitor('App\\Order', 'status');
        $class->namespacedName              = new Name('App\\Invoice');

        (new NodeTraverser($addPublicPropertyVisibilityVisitor))->traverse([$class]);

        $this->assertSame(0, $property->flags);
    }

    public function testDoesNotChangeDifferentProperty(): void
    {
        $property                           = new Property(0, [new PropertyItem('state')]);
        $class                              = new Class_('Order', ['stmts' => [$property]]);
        $addPublicPropertyVisibilityVisitor = new AddPublicPropertyVisibilityVisitor('App\\Order', 'status');
        $class->namespacedName              = new Name('App\\Order');

        (new NodeTraverser($addPublicPropertyVisibilityVisitor))->traverse([$class]);

        $this->assertSame(0, $property->flags);
    }

    public function testAddsPublicVisibilityToPromotedReadonlyProperty(): void
    {
        $param                              = new Param(new Variable('status'), flags: Modifiers::READONLY);
        $classMethod                        = new ClassMethod('__construct', ['params' => [$param]]);
        $class                              = new Class_('Order', ['stmts' => [$classMethod]]);
        $addPublicPropertyVisibilityVisitor = new AddPublicPropertyVisibilityVisitor('App\\Order', 'status');
        $class->namespacedName              = new Name('App\\Order');

        (new NodeTraverser($addPublicPropertyVisibilityVisitor))->traverse([$class]);

        $this->assertSame(Modifiers::PUBLIC | Modifiers::READONLY, $param->flags);
    }

    public function testDoesNotChangePromotedPropertyWithExplicitVisibility(): void
    {
        $flags                              = Modifiers::PRIVATE | Modifiers::READONLY;
        $param                              = new Param(new Variable('status'), flags: $flags);
        $classMethod                        = new ClassMethod('__construct', ['params' => [$param]]);
        $class                              = new Class_('Order', ['stmts' => [$classMethod]]);
        $addPublicPropertyVisibilityVisitor = new AddPublicPropertyVisibilityVisitor('App\\Order', 'status');
        $class->namespacedName              = new Name('App\\Order');

        (new NodeTraverser($addPublicPropertyVisibilityVisitor))->traverse([$class]);

        $this->assertSame(Modifiers::PRIVATE | Modifiers::READONLY, $param->flags);
    }

    public function testDoesNotPromoteRegularConstructorParameter(): void
    {
        $param                              = new Param(new Variable('status'));
        $classMethod                        = new ClassMethod('__construct', ['params' => [$param]]);
        $class                              = new Class_('Order', ['stmts' => [$classMethod]]);
        $addPublicPropertyVisibilityVisitor = new AddPublicPropertyVisibilityVisitor('App\\Order', 'status');
        $class->namespacedName              = new Name('App\\Order');

        (new NodeTraverser($addPublicPropertyVisibilityVisitor))->traverse([$class]);

        $this->assertSame(0, $param->flags);
    }

    public function testDoesNotChangeDifferentPromotedParameter(): void
    {
        $param                              = new Param(new Variable('state'), flags: Modifiers::READONLY);
        $classMethod                        = new ClassMethod('__construct', ['params' => [$param]]);
        $class                              = new Class_('Order', ['stmts' => [$classMethod]]);
        $addPublicPropertyVisibilityVisitor = new AddPublicPropertyVisibilityVisitor('App\\Order', 'status');
        $class->namespacedName              = new Name('App\\Order');

        (new NodeTraverser($addPublicPropertyVisibilityVisitor))->traverse([$class]);

        $this->assertSame(Modifiers::READONLY, $param->flags);
    }

    public function testSkipsMalformedParametersAndFixesMatchingPromotedProperty(): void
    {
        $errorParam                         = new Param(new Error());
        $dynamicNameParam                   = new Param(new Variable(new Variable('dynamic')), flags: Modifiers::READONLY);
        $param                              = new Param(new Variable('status'), flags: Modifiers::READONLY);
        $classMethod                        = new ClassMethod('__construct', [
            'params' => [$errorParam, $dynamicNameParam, $param],
        ]);
        $class                              = new Class_('Order', ['stmts' => [$classMethod]]);
        $addPublicPropertyVisibilityVisitor = new AddPublicPropertyVisibilityVisitor('App\\Order', 'status');
        $class->namespacedName              = new Name('App\\Order');

        (new NodeTraverser($addPublicPropertyVisibilityVisitor))->traverse([$class]);

        $this->assertSame(Modifiers::PUBLIC | Modifiers::READONLY, $param->flags);
        $this->assertSame(Modifiers::READONLY, $dynamicNameParam->flags);
    }

    public function testDoesNotChangePropertyInAnonymousClass(): void
    {
        $property                           = new Property(0, [new PropertyItem('status')]);
        $class                              = new Class_(null, ['stmts' => [$property]]);
        $addPublicPropertyVisibilityVisitor = new AddPublicPropertyVisibilityVisitor('App\\Missing', 'status');

        (new NodeTraverser(new NameResolver(), $addPublicPropertyVisibilityVisitor))->traverse([$class]);

        $this->assertSame(0, $property->flags);
    }
}
