---
title: Configuration
layout: default
nav_order: 3
---

# Configuration
{: .no_toc }

StructArmed configuration is PHP code. Define layers, apply presets, skip known exceptions, and tune preset rules.

## Contents
{: .no_toc }

1. TOC
{:toc}

## Custom Layers And Preset Overrides

```php
<?php

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\Preset;
use Boundwize\StructArmed\Preset\Presets\DddPreset;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeFinalRule;

return Architecture::define()
    ->layer('Domain', 'src/Domain/')
    ->layer('Application', 'src/Application/')
    ->layer('Infrastructure', 'src/Infrastructure/')
    ->skip([
        'tests/Fixtures/',
        'var/cache/*',
        DddPreset::DOMAIN_NO_DATETIME,
        DddPreset::ENTITY_MUST_BE_FINAL => ['src/Legacy/'],
    ])
    ->withPreset(Preset::DDD())

    // Replace a rule with a different configuration.
    ->replaceRule(
        DddPreset::ENTITY_MUST_BE_FINAL,
        new MustBeFinalRule(layer: 'Domain', classNamePattern: '/Entity$|Aggregate$/')
    );
```

## Importing Configuration Files

Split a large configuration into focused files with `import()`. Each imported file returns a callable that receives the `Architecture` builder:

```php
<?php

// structarmed.php
use Boundwize\StructArmed\Architecture;

return Architecture::define()
    ->import(__DIR__ . '/config/layers.php')
    ->import(__DIR__ . '/config/rules.php')
    ->import(__DIR__ . '/config/skips.php');
```

```php
<?php

// config/layers.php
use Boundwize\StructArmed\Architecture;

return static function (Architecture $architecture): void {
    $architecture
        ->layer('Domain', 'src/Domain/')
        ->layer('Application', 'src/Application/')
        ->layer('Infrastructure', 'src/Infrastructure/');
};
```

Because every imported file modifies the same builder, ordering and overrides behave exactly as if the calls were written inline in `structarmed.php`.

Imports may nest — an imported file can import further files:

```php
<?php

// config/rules.php
use Boundwize\StructArmed\Architecture;

return static function (Architecture $architecture): void {
    $architecture
        ->import(__DIR__ . '/rules/ddd.php')
        ->import(__DIR__ . '/rules/quality.php');
};
```

Each file is applied at most once, even when imported from multiple places. Use `imports([...])` to import several files in one call.

`import()` throws a `RuntimeException` when the file does not exist or does not return a callable, so misconfigurations are caught immediately.

Imported files participate in [cache invalidation](../cache/#cache-invalidation): changing any imported file invalidates the analysis cache, exactly like changing `structarmed.php` itself.

## Skipping Paths And Rules

Inside `skip()`, string entries skip files or directories unless they match a registered rule key.

Keyed entries skip paths for a specific rule:

```php
->skip([
    DddPreset::ENTITY_MUST_BE_FINAL => ['src/Legacy/'],
])
```

Rule key constants skip that rule entirely:

```php
->skip([
    DddPreset::DOMAIN_NO_DATETIME,
])
```

Use `skipPath()` / `skipPaths()` and `skipRule()` / `skipRules()` when you prefer explicit method names.

## Replacing Preset Rules

Use `replaceRule()` to swap a preset rule's configuration:

```php
->replaceRule(
    DddPreset::ENTITY_MUST_BE_FINAL,
    new MustBeFinalRule(layer: 'Domain', classNamePattern: '/Entity$|Aggregate$/')
)
```

`replaceRule()` throws `RuleNotFoundException` if the target key does not exist, so typos are caught immediately.

## Custom Extensions

Use [Custom Rules And Presets](../custom-rules-and-presets/) when you want to add project-specific rules or package reusable rule sets.

## Preset Constructor Parameters

```php
->withPreset(Preset::DDD(
    maxComplexity:        3,     // default: 5
    maxMethodLength:      15,    // default: 20
    enforceFinalEntities: false, // default: true
))

->withPreset(Preset::MVC(
    controllerMaxComplexity:   3,  // default: 5
    controllerMaxDependencies: 4,  // default: 5
    viewMaxComplexity:         2,  // default: 3
))

->withPreset(Preset::PSR1(
    sourcePaths: ['src/', 'tests/'], // default: read composer.json PSR-4 paths
))

->withPreset(Preset::PSR12(
    sourcePaths: ['src/', 'tests/'], // default: read composer.json PSR-4 paths
))

->withPreset(Preset::PSR15(
    sourcePaths: ['src/', 'tests/'], // default: read composer.json PSR-4 paths
))

->withPreset(Preset::PSR4(
    sourcePaths: ['src/', 'tests/'], // default: read composer.json PSR-4 paths
))
```
