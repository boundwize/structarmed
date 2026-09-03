---
title: Custom Rules And Presets
layout: default
nav_order: 6
---

# Custom Rules And Presets
{: .no_toc }

Use custom rules when a project needs an architecture check that is not covered by a preset. Use custom presets when you want to package layers and rules so they can be reused across projects.

## Contents
{: .no_toc }

1. TOC
{:toc}

## Adding A Rule From Configuration

Use `rule()` to add a project-specific rule under your own rule key.

```php
<?php

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Rules\Layer\MayNotDependOnRule;
use Boundwize\StructArmed\Rule\Rules\Method\MustHaveReturnTypeRule;

return Architecture::define()
    ->layer('Domain', 'src/Domain/')
    ->layer('Infrastructure', 'src/Infrastructure/')
    ->rule(
        'domain.must_not_depend_on_infrastructure',
        new MayNotDependOnRule(from: 'Domain', to: 'Infrastructure', toPath: 'Infrastructure')
    )
    ->rule(
        'domain.public_methods_must_have_return_types',
        new MustHaveReturnTypeRule(layer: 'Domain')
    );
```

`rule()` can overwrite an existing key silently. Use `replaceRule()` when you want StructArmed to verify that the target rule already exists.

See [Available Rules](../available-rules/) when you want to reuse one of StructArmed's built-in rule classes before writing your own.

## Rule Keys

Use stable, descriptive rule keys. A common pattern is:

```text
area.subject_constraint
```

For example:

```php
'domain.public_methods_must_have_return_types'
```

Rule keys are used in reports, skips, baselines, and preset constants, so avoid changing them casually after they are published.

## Writing A Custom Rule Class

A custom rule class implements `Boundwize\StructArmed\Rule\RuleInterface`.

```php
<?php

namespace App\Architecture\Rules;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function sprintf;

final readonly class ServiceClassMustBeFinalRule implements RuleInterface
{
    public function appliesTo(ClassNode $classNode): bool
    {
        return $classNode->isClass()
            && $classNode->isInLayer('Application')
            && $classNode->nameEndsWith('Service');
    }

    public function evaluate(ClassNode $classNode): ?RuleViolation
    {
        if ($classNode->isFinal) {
            return null;
        }

        return new RuleViolation(
            message:   sprintf('Service class [%s] must be final', $classNode->className),
            file:      $classNode->file,
            line:      $classNode->line,
            className: $classNode->className,
            layer:     $classNode->layer,
        );
    }
}
```

Register the rule in `structarmed.php`:

```php
<?php

use App\Architecture\Rules\ServiceClassMustBeFinalRule;
use Boundwize\StructArmed\Architecture;

return Architecture::define()
    ->layer('Application', 'src/Application/')
    ->rule(
        'application.service_classes_must_be_final',
        new ServiceClassMustBeFinalRule()
    );
```

## Reading Usage Flags In A Custom Rule

Every `ClassNode` carries four usage flags describing how the class-like is used elsewhere in the scanned paths:

| Flag | Meaning |
| --- | --- |
| `$classNode->isExtended` | Another scanned class (or anonymous class) extends this class, directly or through inheritance |
| `$classNode->isImplemented` | A scanned class implements this interface (directly or through inheritance), or another scanned interface extends it |
| `$classNode->isReferenced` | Another scanned scope references it as a dependency: a type hint, an `instanceof` check, a `::class` constant, a static call, a trait use, a class-name string, and so on |
| `$classNode->isInstantiated` | Another scanned scope instantiates it: `new X`, `new self`/`static`/`parent`, a constant class expression such as `new (X::class)`, or a resolvable `ReflectionClass` construction |

Collecting this usage information costs extra analysis time, so the analyser only computes it when an active rule declares that it needs it. A custom rule declares that by implementing one of three marker interfaces instead of the plain `Boundwize\StructArmed\Rule\RuleInterface` (each marker extends it, so no other change is needed):

| Marker interface | Flags populated |
| --- | --- |
| `Boundwize\StructArmed\Rule\ExtendedClassAwareRuleInterface` | `$isExtended`, `$isReferenced`, `$isInstantiated` |
| `Boundwize\StructArmed\Rule\UsedInterfaceAwareRuleInterface` | `$isImplemented`, `$isReferenced` |
| `Boundwize\StructArmed\Rule\UsedTraitAwareRuleInterface` | `$isReferenced` |

Without a matching marker on at least one active rule, the corresponding flags keep their default `false` — reading them from a rule that only implements `RuleInterface` reports every class-like as unused.

```php
<?php

namespace App\Architecture\Rules;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Rule\UsedInterfaceAwareRuleInterface;

use function sprintf;

final readonly class ContractMustBeImplementedRule implements UsedInterfaceAwareRuleInterface
{
    public function appliesTo(ClassNode $classNode): bool
    {
        return $classNode->isInterface
            && $classNode->isInLayer('Contracts');
    }

    public function evaluate(ClassNode $classNode): ?RuleViolation
    {
        if ($classNode->isImplemented || $classNode->isReferenced) {
            return null;
        }

        return new RuleViolation(
            message:   sprintf('Contract [%s] must be implemented or referenced', $classNode->className),
            file:      $classNode->file,
            line:      $classNode->line,
            className: $classNode->className,
            layer:     $classNode->layer,
        );
    }
}
```

The built-in [YAGNI preset](../presets/) rules follow this pattern: `MustBeUsedInterfaceRule` implements `UsedInterfaceAwareRuleInterface`, `MustBeUsedTraitRule` implements `UsedTraitAwareRuleInterface`, and `MustBeUsedAbstractClassRule` and `ExtendedClassMustBeAbstractOrInstantiatedRule` implement `ExtendedClassAwareRuleInterface`.

Trade-off: only usage within the scanned paths is known. A class-like used solely by a consumer outside the scan — a vendor package, an unscanned directory, runtime-fed dynamic construction — is reported as if unused. Widen the scan, or use `skipRule()` and skip paths where such consumers exist.

## Analysing Functions, Closures, And Anonymous Classes

Named functions, closures, arrow functions, and anonymous classes are collected alongside named classes:

| Node | Represents | Identified by |
| --- | --- | --- |
| `Boundwize\StructArmed\Analyser\FunctionNode` | A named function declaration (`function foo() {}`), global or namespaced | `$functionName` (fully qualified) |
| `Boundwize\StructArmed\Analyser\AnonymousFunctionNode` | A closure (`function () {}`) or arrow function (`fn () => ...`) | `$file` and `$line`, plus `$enclosingClassName` / `$enclosingFunctionName` |
| `Boundwize\StructArmed\Analyser\AnonymousClassNode` | An anonymous class declaration (`new class ... {}`) | `$file` and `$line`, plus `$enclosingClassName` / `$enclosingFunctionName` |

`FunctionNode` and `AnonymousFunctionNode` both carry the body-level facts a `ClassNode` has — `$dependencies`, `$functionCalls`, `$superglobals`, `$languageConstructs`, `$layer` / `$layers` — plus `$paramCount`, `$hasReturnType`, `$cyclomaticComplexity`, and `$lineCount`. The same query helpers are available: `isInLayer()`, `dependsOn()`, `dependsOnNamespace()`, `callsFunction()`, `usesLanguageConstruct()`, and `accessesSuperglobals()`. A `FunctionNode` also has `shortName()`, `nameStartsWith()`, `nameEndsWith()`, and `nameMatches()`; an `AnonymousFunctionNode` has `$isArrowFunction`, `$isStatic`, `getType()`, and `enclosingScopeName()`.

A closure declared inside a class or a named function is counted on both nodes: the enclosing `ClassNode` (or `FunctionNode`) keeps seeing everything the closure does, exactly as it sees its own method bodies, and the `AnonymousFunctionNode` reports the closure body on its own.

Anonymous classes (`new class ... {}`) are collected the same way, as `Boundwize\StructArmed\Analyser\AnonymousClassNode`: identified by `$file` and `$line` plus `$enclosingClassName` / `$enclosingFunctionName` (with `enclosingScopeName()` and `AnonymousClassNode::FILE_SCOPE`), and carrying `$extends`, `$implements`, `$traits`, `$layer` / `$layers` with `isInLayer()`, and `$hasEmptyParentheses` — whether the declaration spells `new class () {}` although it passes no constructor argument. An anonymous class never becomes a `ClassNode`; the named class-like or function declaring it keeps seeing its body, exactly as it sees a closure's.

Rules opt in to these nodes by implementing `Boundwize\StructArmed\Rule\FunctionRuleInterface`, `Boundwize\StructArmed\Rule\AnonymousFunctionRuleInterface`, and/or `Boundwize\StructArmed\Rule\AnonymousClassRuleInterface`. All share the `appliesTo()` / `evaluate()` method names with `RuleInterface`, each typed against its own node kind. Global skip paths, rule-scoped `skip()` paths, and `skipRule()` apply the same way. Function-likes and anonymous classes are not part of the declarative `ruleset()` layer-dependency check.

```php
<?php

namespace App\Architecture\Rules;

use Boundwize\StructArmed\Analyser\FunctionNode;
use Boundwize\StructArmed\Rule\FunctionRuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function sprintf;

final readonly class FunctionsMustNotAccessSuperglobalsRule implements FunctionRuleInterface
{
    public function __construct(private string $layer)
    {
    }

    public function appliesTo(FunctionNode $functionNode): bool
    {
        return $functionNode->isInLayer($this->layer);
    }

    public function evaluate(FunctionNode $functionNode): ?RuleViolation
    {
        if (! $functionNode->accessesSuperglobals()) {
            return null;
        }

        return new RuleViolation(
            message:      sprintf('Function [%s()] must not access superglobals', $functionNode->functionName),
            file:         $functionNode->file,
            line:         $functionNode->line,
            className:    $functionNode->functionName,
            layer:        $functionNode->layer,
            functionName: $functionNode->functionName,
        );
    }
}
```

An anonymous-function rule looks the same with `AnonymousFunctionNode` in the signatures:

```php
<?php

namespace App\Architecture\Rules;

use Boundwize\StructArmed\Analyser\AnonymousFunctionNode;
use Boundwize\StructArmed\Rule\AnonymousFunctionRuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function sprintf;

final readonly class ClosuresMustNotAccessSuperglobalsRule implements AnonymousFunctionRuleInterface
{
    public function __construct(private string $layer)
    {
    }

    public function appliesTo(AnonymousFunctionNode $anonymousFunctionNode): bool
    {
        return $anonymousFunctionNode->isInLayer($this->layer);
    }

    public function evaluate(AnonymousFunctionNode $anonymousFunctionNode): ?RuleViolation
    {
        if (! $anonymousFunctionNode->accessesSuperglobals()) {
            return null;
        }

        return new RuleViolation(
            message:   sprintf(
                '%s in [%s] must not access superglobals',
                $anonymousFunctionNode->getType(),
                $anonymousFunctionNode->enclosingScopeName()
            ),
            file:      $anonymousFunctionNode->file,
            line:      $anonymousFunctionNode->line,
            className: $anonymousFunctionNode->enclosingScopeName(),
            layer:     $anonymousFunctionNode->layer,
        );
    }
}
```

An anonymous-class rule receives an `AnonymousClassNode` for every anonymous class in the scanned paths. For example, this rule requires anonymous classes in one layer to implement a project-specific marker interface:

```php
<?php

namespace App\Architecture\Rules;

use Boundwize\StructArmed\Analyser\AnonymousClassNode;
use Boundwize\StructArmed\Rule\AnonymousClassRuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function in_array;
use function sprintf;

final readonly class AnonymousClassesMustImplementRule implements AnonymousClassRuleInterface
{
    public function __construct(
        private string $layer,
        private string $interface,
    ) {
    }

    public function appliesTo(AnonymousClassNode $anonymousClassNode): bool
    {
        return $anonymousClassNode->isInLayer($this->layer);
    }

    public function evaluate(AnonymousClassNode $anonymousClassNode): ?RuleViolation
    {
        if (in_array($this->interface, $anonymousClassNode->implements, true)) {
            return null;
        }

        return new RuleViolation(
            message:   sprintf(
                'Anonymous class in [%s] must implement [%s]',
                $anonymousClassNode->enclosingScopeName(),
                $this->interface,
            ),
            file:      $anonymousClassNode->file,
            line:      $anonymousClassNode->line,
            className: $anonymousClassNode->enclosingScopeName(),
            layer:     $anonymousClassNode->layer,
        );
    }
}
```

Register it through `Architecture::rule()` like any other custom rule. The analyser invokes it only for anonymous classes because it implements `AnonymousClassRuleInterface`.

One rule class can also implement several of these interfaces at once; PHP then requires the shared methods to widen the parameter to a union type (for example `appliesTo(FunctionNode|AnonymousFunctionNode|AnonymousClassNode $node): bool`) and the rule branches on the node type inside.

`RuleViolation::$className` is required, so a function rule passes the function name there (and, optionally, in the dedicated `functionName` field, which the JSON report emits as `"function"`); an anonymous-function or anonymous-class rule passes `enclosingScopeName()`, which is the enclosing class-like or named function, or `FILE_SCOPE` (`'file scope'`) for one in top-level procedural code.

## Making A Custom Rule Fixable

Use `Boundwize\StructArmed\Rule\FixableInterface` when a custom rule can safely rewrite the offending source file.

`Boundwize\StructArmed\Rule\FixableInterface` is the only contract required by `vendor/bin/structarmed analyse --fix`.

Implement `Boundwize\StructArmed\Rule\FixableInterface` directly when the rule owns the complete fix logic. Add fix support only when the rule can make a deterministic change on disk.

```diff
+ use Boundwize\StructArmed\Rule\FixableInterface;
  use Boundwize\StructArmed\Rule\RuleInterface;
  use Boundwize\StructArmed\Rule\RuleViolation;

- final readonly class SomeRule implements RuleInterface
+ final readonly class SomeRule implements RuleInterface, FixableInterface
    {
+     public function fix(RuleViolation $ruleViolation): bool
+     {
+         return $this->rewriteSourceFile($ruleViolation);
+     }
    }
```

`rewriteSourceFile()` represents your own custom fixer implementation. `fix()` receives the selected `RuleViolation`; use the violation data, such as `file`, `line`, `className`, `methodName`, `constantName`, or `propertyName`, to target the rewrite. Return `true` only when the source file was actually changed; return `false` when the rule cannot safely apply a fix.

For PHP-Parser based rewrites, extend `Boundwize\StructArmed\Rule\Fixer\PhpParser\AbstractPhpParserFixableRule`.
`AbstractPhpParserFixableRule` implements `FixableInterface`, owns a shared cached `PhpParserFixerProcessor`, and lets the rule provide only the `PhpParser\NodeVisitor` for the selected violation.

```diff
+ use Boundwize\StructArmed\Rule\Fixer\PhpParser\AbstractPhpParserFixableRule;
+ use Boundwize\StructArmed\Rule\Fixer\PhpParser\Class_\AddFinalClassVisitor;
+ use Boundwize\StructArmed\Rule\RuleViolation;

- final readonly class ServiceClassMustBeFinalRule implements RuleInterface
+ final readonly class ServiceClassMustBeFinalRule extends AbstractPhpParserFixableRule implements RuleInterface
    {
+     protected function createFixerVisitor(RuleViolation $ruleViolation): AddFinalClassVisitor
+     {
+         return new AddFinalClassVisitor($ruleViolation->className);
+     }
    }
```

StructArmed ships `Boundwize\StructArmed\Rule\Fixer\PhpParser\Class_\AddFinalClassVisitor` for this final-class fixer shape. It is a small `PhpParser\NodeVisitor` that targets one class and adds the `final` modifier.

`AbstractPhpParserFixableRule` provides a shared cached `PhpParserFixerProcessor` instance, and `PhpParserFixerProcessor` handles parsing, format-preserving printing, and writing the updated file back to disk.

Built-in rules follow the same pattern: `MustBeFinalRule` returns `AddFinalClassVisitor`, `MustDeclareConstantVisibilityRule` returns `AddPublicConstantVisibilityVisitor`, `MustDeclareMethodVisibilityRule` returns `AddPublicMethodVisibilityVisitor`, and `MustDeclarePropertyVisibilityRule` returns `AddPublicPropertyVisibilityVisitor` from `createFixerVisitor()` rather than introducing extra wrapper fixer classes.

- Direct `Boundwize\StructArmed\Rule\FixableInterface` implementations should keep all file changes inside `fix()`.
- `createFixerVisitor()` should return a `PhpParser\NodeVisitor` that safely no-ops when the violation does not contain enough data to fix.
- Return `true` only when the source file was actually changed.
- The console report marks those violations as fixable and shows a `--fix` hint.

Keep fixers deterministic and narrowly scoped. A failed or skipped fix should return `false` so StructArmed can leave the violation in the report.

## Custom Presets

A custom preset is a class that implements `Boundwize\StructArmed\Preset\PresetInterface`. Inside `apply()`, add the layers and rules you want to reuse.

```php
<?php

namespace App\Architecture;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\PresetInterface;
use Boundwize\StructArmed\Rule\Rules\Method\MustHaveReturnTypeRule;

final class MyPreset implements PresetInterface
{
    public const METHODS_MUST_HAVE_RETURN_TYPES = 'source.methods_must_have_return_types';

    public function apply(Architecture $architecture): void
    {
        $architecture
            ->layer('Source', 'src/')
            ->rule(
                self::METHODS_MUST_HAVE_RETURN_TYPES,
                new MustHaveReturnTypeRule(layer: 'Source')
            );
    }
}
```

Register it in `structarmed.php`:

```php
<?php

use App\Architecture\MyPreset;
use Boundwize\StructArmed\Architecture;

return Architecture::define()
    ->withPreset(new MyPreset());
```

## Skipping Custom Rules

Custom rules use the same skip APIs as preset rules.

```php
return Architecture::define()
    ->skipRule(MyPreset::METHODS_MUST_HAVE_RETURN_TYPES)
    ->skip([
        MyPreset::METHODS_MUST_HAVE_RETURN_TYPES => ['src/Legacy/'],
    ])
    ->withPreset(new MyPreset());
```

## When To Use Each Extension Point

Use `rule()` when one project needs one extra check.

Use a custom `RuleInterface` class when the check itself is new behavior; add `FunctionRuleInterface` / `AnonymousFunctionRuleInterface` / `AnonymousClassRuleInterface` when it must also cover named functions, closures, or anonymous classes.

Use a custom `PresetInterface` class when several layers and rules should be applied together or reused across repositories.
