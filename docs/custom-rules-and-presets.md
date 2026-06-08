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

Use a custom `RuleInterface` class when the check itself is new behavior.

Use a custom `PresetInterface` class when several layers and rules should be applied together or reused across repositories.
