---
title: Presets
layout: default
nav_order: 7
---

# Presets
{: .no_toc }

StructArmed ships with presets for common PHP standards and architecture styles.

## Contents
{: .no_toc }

1. TOC
{:toc}

## Available Presets

| Preset | Rules |
|---|---|
| `Preset::PSR4()` | Verifies configured source paths exist in composer.json `autoload` or `autoload-dev` PSR-4 mappings |
| `Preset::PSR1()` | Basic Coding Standard checks: PHP tags, valid UTF-8, UTF-8 without BOM, symbols vs side effects, PSR-4 class placement, StudlyCaps class names, upper-case class constants, camelCase methods |
| `Preset::PSR12()` | Extends PSR-1: PHP keyword constants (`true`, `false`, and `null`) must be lowercase, and all methods, constants, and properties must declare explicit visibility |
| `Preset::PER()` | [PER Coding Style](https://www.php-fig.org/per/coding-style/): extends PSR-12 (and, through it, PSR-1) and adds PascalCase enum case names, no `protected` enum methods or constants, one enum case per line, no empty `()` on anonymous classes that pass no constructor argument (`new class {}`, not `new class () {}`), and no empty `()` on attributes that pass no argument (`#[Foo]`, not `#[Foo()]`); the enum-case-line, anonymous-class, attribute, and `protected` rules support `--fix` |
| `Preset::PSR15()` | `*Middleware` classes must implement PSR-15 `MiddlewareInterface`; `*Handler` classes must implement PSR-15 `RequestHandlerInterface`; StructArmed also enforces matching `Middleware`/`Handler` suffixes for implementations of those interfaces |
| `Preset::DDD()` | Layer isolation, entity/VO/repository/event/service conventions, including keeping Doctrine ORM repository inheritance out of the Domain layer |
| `Preset::MVC()` | Layer isolation, thin controllers, model/view/service rules, return types for helper functions |
| `Preset::YAGNI()` | Speculative-abstraction cleanup: interfaces must be implemented by a class or extended by another interface, abstract classes must be extended, traits must be used, extended classes that are never instantiated must be abstract, and named functions must be called — a dependency reference (type hint, `instanceof`, `::class`, static call, a class-name string, ...) also counts as usage within the scanned paths, while only instantiation (`new X`, `new self`/`static`/`parent`, or a constant class expression such as `new (X::class)`) keeps an extended class concrete. All rules support `--fix`, removing the unused declaration or adding the `abstract` modifier |
| `Preset::CODEQUALITY()` | General readability conventions independent of any architecture style: closures and arrow functions that do not read `$this` must be declared `static`, and plain decimal numeric literals of `1_000_000` or more must group their digits with `_` separators (`1000500` becomes `1_000_500`). Both rules support `--fix`. Tune the literal threshold with `replaceRule(CodeQualityPreset::LARGE_NUMERIC_LITERALS_MUST_USE_SEPARATOR, new LargeNumericLiteralMustUseSeparatorRule(minimum: 1_000))` |
| `Preset::STABILITY()` | Keeps the stable `@api` surface apart from `@internal` code: a class-like must not be tagged both `@api` and `@internal`, an `@api` class or interface must not extend a scanned `@internal` one, directly or through any parent, and an `@api` class-like must not name a scanned `@internal` type in a public or protected signature. Class-likes without either tag are not checked |
| `Preset::ENUM()` | Enum declaration conventions for string-backed enums: a case must not be backed by an empty string (`case Pending = '';`), nor by a value with leading or trailing whitespace (`case Pending = ' pending';`, `"active\t"`, `' '`). Whitespace is what PHP's native `trim()` strips; whitespace inside a value (`'in progress'`) stays valid, and an empty string is reported only by the empty-value rule. `'0'`, int-backed and pure enums are accepted, and cases whose backing value the analyser cannot resolve statically are skipped. No `--fix`: changing a backing value may break APIs or persisted data |

## Initialize Presets

```bash
vendor/bin/structarmed init --preset=psr4
vendor/bin/structarmed init --preset=psr1
vendor/bin/structarmed init --preset=psr12
vendor/bin/structarmed init --preset=per
vendor/bin/structarmed init --preset=psr15
vendor/bin/structarmed init --preset=mvc
vendor/bin/structarmed init --preset=ddd
vendor/bin/structarmed init --preset=yagni
vendor/bin/structarmed init --preset=codequality
vendor/bin/structarmed init --preset=stability
vendor/bin/structarmed init --preset=enum
vendor/bin/structarmed init --preset=all
```

## Combining Presets

```php
return Architecture::define()
    ->withPresets(
        Preset::PSR4(),
        Preset::PSR1(),
        Preset::PSR12(),
        Preset::PER(),
        Preset::PSR15(),
        Preset::MVC(),
        Preset::DDD(),
        Preset::YAGNI(),
        Preset::CODEQUALITY(),
        Preset::STABILITY(),
        Preset::ENUM(),
    );
```

## Rule Key Constants

Every built-in preset rule has a public constant for its registration key. When referring to a rule supplied by a preset, use that constant instead of retyping its string value so IDEs and static analysis can catch misspelled constant names.

Load the preset before replacing one of its rules:

```php
<?php

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\Preset;
use Boundwize\StructArmed\Preset\Presets\DddPreset;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeFinalRule;

return Architecture::define()
    ->withPreset(Preset::DDD())
    ->skipRule(DddPreset::DOMAIN_NO_BASE_EXCEPTION)
    ->replaceRule(
        DddPreset::ENTITY_MUST_BE_FINAL,
        new MustBeFinalRule(layer: 'Domain', classNamePattern: '/(?:Entity|Aggregate)$/')
    );
```

`DddPreset::ENTITY_MUST_BE_FINAL` is the string `'ddd.entity.must_be_final'`. Referencing the constant alone does not load the preset or register a rule. `replaceRule()` requires an existing key and throws `RuleNotFoundException` if it is missing; `rule()` registers a rule and overwrites any existing rule with the same key.

When [using built-in rules directly](../available-rules/#using-built-in-rules-directly) without a preset, choose your own stable keys, such as `'domain.entities_must_be_final'`. String keys are valid for these registrations. If you package them in a [custom preset](../custom-rules-and-presets/#custom-presets), expose your own public constants and use those when skipping or replacing its rules.
