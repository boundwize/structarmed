---
title: Configuration
layout: default
nav_order: 3
---

# Configuration

StructArmed configuration is PHP code. Define layers, apply presets, skip known exceptions, replace preset rules, or add project-specific rules.

## Contents
{: .no_toc }

1. TOC
{:toc}

## Custom Layers And Rules

```php
<?php

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\Preset;
use Boundwize\StructArmed\Preset\Presets\DddPreset;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeFinalRule;
use Boundwize\StructArmed\Rule\Rules\Layer\MayNotDependOnRule;
use Boundwize\StructArmed\Rule\Rules\Method\MustHaveReturnTypeRule;

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
    )

    // Add your own custom rule.
    ->rule(
        'domain.must_not_depend_on_infrastructure',
        new MayNotDependOnRule(from: 'Domain', to: 'Infrastructure', toPath: 'Infrastructure')
    )
    ->rule(
        'domain.public_methods_must_have_return_types',
        new MustHaveReturnTypeRule(layer: 'Domain')
    );
```

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

## Adding Custom Rules

Use `rule()` to add a new project-specific rule:

```php
->rule(
    'domain.public_methods_must_have_return_types',
    new MustHaveReturnTypeRule(layer: 'Domain')
)
```

`rule()` can overwrite an existing key silently. Use `replaceRule()` when you want StructArmed to verify that the target rule already exists.

## Custom Presets

A custom preset is a class that implements `Boundwize\StructArmed\Preset\PresetInterface`. Inside `apply()`, add the layers and rules you want to reuse.

```php
<?php

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
