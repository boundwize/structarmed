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
| `Preset::PSR1()` | Basic Coding Standard checks: PHP tags, valid UTF-8, UTF-8 without BOM, symbols vs side effects, PSR-4 class placement, StudlyCaps class names, upper-case class constants, camelCase methods |
| `Preset::PSR12()` | Extends PSR-1: all methods, constants, and properties must declare explicit visibility |
| `Preset::PSR15()` | `*Middleware` classes must implement PSR-15 `MiddlewareInterface`; `*Handler` classes must implement PSR-15 `RequestHandlerInterface`; StructArmed also enforces matching `Middleware`/`Handler` suffixes for implementations of those interfaces |
| `Preset::PSR4()` | Verifies configured source paths exist in composer.json `autoload` or `autoload-dev` PSR-4 mappings |
| `Preset::DDD()` | Layer isolation, entity/VO/repository/event/service conventions |
| `Preset::MVC()` | Layer isolation, thin controllers, model/view/service rules |
| `Preset::YAGNI()` | Speculative-abstraction cleanup: interfaces must be implemented by a class or extended by another interface, abstract classes must be extended, traits must be used, and extended classes that are never instantiated must be abstract — a dependency reference (type hint, `instanceof`, `::class`, static call, a class-name string, ...) also counts as usage within the scanned paths, while only instantiation (`new X`, `new self`/`static`/`parent`, or a constant class expression such as `new (X::class)`) keeps an extended class concrete. All rules support `--fix`, removing the unused declaration or adding the `abstract` modifier |

## Initialize Presets

```bash
vendor/bin/structarmed init --preset=psr4
vendor/bin/structarmed init --preset=psr1
vendor/bin/structarmed init --preset=psr12
vendor/bin/structarmed init --preset=psr15
vendor/bin/structarmed init --preset=mvc
vendor/bin/structarmed init --preset=ddd
vendor/bin/structarmed init --preset=yagni
vendor/bin/structarmed init --preset=all
```

## Combining Presets

```php
return Architecture::define()
    ->withPresets(
        Preset::PSR4(),
        Preset::PSR1(),
        Preset::PSR12(),
        Preset::PSR15(),
        Preset::MVC(),
        Preset::DDD(),
        Preset::YAGNI(),
    );
```

## Rule Key Constants

Every preset rule has a public constant. Use constants instead of raw strings so IDEs and static analysis can catch mistakes.

```php
// Good: caught by IDE and static analysis.
DddPreset::ENTITY_MUST_BE_FINAL

// Bad: typo silently does nothing.
'ddd.entity.must_be_fnal'
```
