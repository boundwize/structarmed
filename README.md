# StructArmed

<p align="center">
    <img src="https://github.com/user-attachments/assets/18024dc9-8658-40ca-abec-2df7b675a3b8" alt="StructArmed" width="300">
</p>

<p align="center">
    Configurable PHP architecture guards — define your layers and rules, then keep them enforced.
</p>

[![Latest Version](https://img.shields.io/github/release/boundwize/structarmed.svg?style=flat-square)](https://github.com/boundwize/structarmed/releases)
[![ci build](https://github.com/boundwize/structarmed/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/boundwize/structarmed/actions/workflows/ci.yml)
[![Code Coverage](https://codecov.io/gh/boundwize/structarmed/branch/main/graph/badge.svg)](https://codecov.io/gh/boundwize/structarmed)
[![PHPStan](https://img.shields.io/badge/style-level%20max-brightgreen.svg?style=flat-square&label=phpstan)](https://github.com/phpstan/phpstan)
[![Downloads](https://poser.pugx.org/boundwize/structarmed/downloads)](https://packagist.org/packages/boundwize/structarmed)

![Windows](https://img.shields.io/badge/Windows-supported-0078D6?logo=windows&logoColor=white&labelColor=555555)
![macOS](https://img.shields.io/badge/macOS-supported-C084FC?logo=apple&logoColor=white&labelColor=555555)
![Linux](https://img.shields.io/badge/Linux-supported-FCC624?logo=linux&logoColor=black&labelColor=555555)

## Turn architecture rules into executable checks

- Make architecture decisions executable, not just documented.
- Start with ready-made presets for common architecture styles.
- Tune, override, or skip individual preset rules in native PHP code.
- Catch boundary violations before they quietly become conventions.

## Installation

```bash
composer require --dev boundwize/structarmed
```

## Quick start

```bash
# defaults to --preset=psr4
vendor/bin/structarmed init

# verify source paths match composer.json PSR-4 mappings
vendor/bin/structarmed init --preset=psr4

# enforce basic coding standard (tags, StudlyCaps, camelCase)
vendor/bin/structarmed init --preset=psr1

# extends PSR-1: require explicit visibility on all members
vendor/bin/structarmed init --preset=psr12

# thin controllers, model/view/service layer rules
vendor/bin/structarmed init --preset=mvc

# layer isolation, entity/VO/repository/event/service conventions
vendor/bin/structarmed init --preset=ddd

# enable all presets at once
vendor/bin/structarmed init --preset=all
```

Generates a `structarmed.php` in your project root. Edit it to match your structure, then run:

```bash
vendor/bin/structarmed analyse
```

If violations are found, the output reports each one:

<img width="498" height="214" alt="Image" src="https://github.com/user-attachments/assets/51368d3d-b5bc-4d09-8cbb-cd0b55fca370" />

If everything passes, you get a clean summary:

<img width="460" height="93" alt="Image" src="https://github.com/user-attachments/assets/aeb81a6a-20eb-40be-b68b-889b0349ac37" />

## Configuration

### Default

```php
<?php

// structarmed.php
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\Preset;

return Architecture::define()
    ->withPreset(Preset::PSR4());
```

### Multiple presets

```php
->withPresets(Preset::PSR4(), Preset::PSR1(), Preset::PSR12(), Preset::MVC(), Preset::DDD())
```

### Cache directory

StructArmed stores analysis cache in the system temp directory by default. You can configure a project cache directory:

```php
<?php

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\Preset;

return Architecture::define()
    ->cacheDirectory('var/cache/structarmed')
    ->withPreset(Preset::PSR4());
```

Relative cache directories are resolved from the project root. `--config` also controls the cache directory used by `analyse` and `--clear-cache`.

### Custom layers and rules

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

    // Replace a rule with a different configuration
    ->replaceRule(
        DddPreset::ENTITY_MUST_BE_FINAL,
        new MustBeFinalRule(layer: 'Domain', classNamePattern: '/Entity$|Aggregate$/')
    )

    // Add your own custom rule
    ->rule(
        'domain.must_not_depend_on_infrastructure',
        new MayNotDependOnRule(from: 'Domain', to: 'Infrastructure', toPath: 'Infrastructure')
    )
    ->rule(
        'domain.public_methods_must_have_return_types',
        new MustHaveReturnTypeRule(layer: 'Domain')
    );
```

Inside `skip()`, string entries skip files or directories unless they match a registered rule key, keyed entries
skip paths for one specific rule, and rule key constants skip that rule entirely. You can also use
`skipPath()` / `skipPaths()` and `skipRule()` / `skipRules()` when you prefer the explicit methods.

Use `replaceRule()` to swap a preset rule's configuration — it throws `RuleNotFoundException` if the key does not exist, so a typo is caught immediately. Use `rule()` to add new custom rules; it can also overwrite an existing key, but silently, with no verification that the target exists.

## Layer resolution

Layers are resolved by file path — no attributes needed on classes:

```
src/Domain/     → 'Domain'
src/Application/ → 'Application'
src/Infrastructure/ → 'Infrastructure'
```

### Layer patterns (namespace-based layers)

When your architecture is expressed through namespace conventions rather than directory structure, use `layerPattern()` to resolve layers by matching the fully-qualified class name against a regex:

```php
return Architecture::define()
    ->layerPattern('API',    '/^App\\\\API\\\\.*$/')
    ->layerPattern('HTTP',   '/^App\\\\HTTP\\\\.*$/')
    ->layerPattern('Router', '/^App\\\\Router\\\\.*$/');
```

An optional third argument excludes classes whose FQN matches a second regex, even when the first matches:

```php
// HTTP layer: includes everything under App\HTTP\, except App\HTTP\URI
->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/', '/^App\\\\HTTP\\\\URI$/')
->layerPattern('URI',  '/^App\\\\HTTP\\\\URI$/')
```

### Declarative ruleset

Once layers are defined (via `layer()` or `layerPattern()`), declare which layers each layer is allowed to depend on. Any dependency that resolves to a layer not in the allowed list is a violation:

```php
return Architecture::define()
    ->layerPattern('API',      '/^App\\\\API\\\\.*$/')
    ->layerPattern('HTTP',     '/^App\\\\HTTP\\\\.*$/')
    ->layerPattern('Database', '/^App\\\\Database\\\\.*$/')
    ->ruleset([
        'API'      => ['HTTP'],           // API may only depend on HTTP
        'HTTP'     => ['Database'],       // HTTP may only depend on Database
        'Database' => [],                 // Database may not depend on any layer
    ]);
```

Layers absent from the ruleset keys are not checked. Dependencies on external (non-registered) classes are always allowed.

Same-layer dependencies are always allowed regardless of the ruleset.

### Skipping class-level violations

When a specific class-to-class dependency is a known exception, suppress it without disabling the whole layer rule:

```php
->skipClassViolation('App\\HTTP\\ResponseTrait', [
    'App\\Pager\\PagerInterface',
])
->skipClassViolation('App\\Log\\ChromeLoggerHandler', 'App\\HTTP\\ResponseInterface')
```

The first argument is the fully-qualified violating class name; the second is one or more dependency FQNs to ignore for that class.

### Excluding paths from ruleset checks only

Test files often cross layer boundaries by design. Use `skipPathsForRuleset()` to exclude paths from ruleset evaluation while still scanning them for all other rules (e.g. PSR-4 namespace checks):

```php
return Architecture::define()
    ->withPresets(Preset::PSR4())
    ->layerPattern('HTTP',     '/^App\\\\HTTP\\\\.*$/')
    ->layerPattern('Database', '/^App\\\\Database\\\\.*$/')
    ->ruleset(['HTTP' => [], 'Database' => ['HTTP']])
    ->skipPathsForRuleset(['*tests*', '*fixtures*']);
```

This is different from `skipPaths()` / `skipPath()`, which exclude files from **all** analysis.

### Custom presets

A custom preset is a class that implements `Boundwize\StructArmed\Preset\PresetInterface`. Inside `apply()`, add the layers and
rules you want to reuse:

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

### Preset constructor parameters

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

->withPreset(Preset::PSR4(
    sourcePaths: ['src/', 'tests/'], // default: read composer.json PSR-4 paths
))
```

## Available presets

| Preset | Rules |
|---|---|
| `Preset::PSR1()` | Basic Coding Standard checks: PHP tags, UTF-8 without BOM, symbols vs side effects, PSR-4 class placement, StudlyCaps class names, upper-case class constants, camelCase methods |
| `Preset::PSR12()` | Extends PSR-1: all methods, constants, and properties must declare explicit visibility |
| `Preset::PSR4()` | Verifies configured source paths exist in composer.json `autoload` or `autoload-dev` PSR-4 mappings |
| `Preset::DDD()` | Layer isolation, entity/VO/repository/event/service conventions |
| `Preset::MVC()` | Layer isolation, thin controllers, model/view/service rules |

## PHPUnit extension

Run architecture checks as part of your test suite:

```xml
<!-- phpunit.xml -->
<extensions>
    <bootstrap class="Boundwize\StructArmed\PHPUnit\StructArmedExtension"/>
</extensions>
```

Violations cause the test run to fail before any tests execute.

## CLI analyse commands

```bash
# Analyse with default config discovery
vendor/bin/structarmed analyse
vendor/bin/structarmed analyze

# Analyse only specific paths
vendor/bin/structarmed analyse src
vendor/bin/structarmed analyze src tests

# Custom config path
vendor/bin/structarmed analyse --config=path/to/structarmed.php
vendor/bin/structarmed analyze --config=path/to/structarmed.php

# JSON output (for CI tools)
vendor/bin/structarmed analyse --report=json
vendor/bin/structarmed analyze --report=json

# structarmed is running parallel by default
# to disable parallel processing (e.g. when debugging worker issues), pass `--disable-parallel`:
vendor/bin/structarmed analyse --disable-parallel
```

## Tips

### Rule key constants

Every preset rule has a public constant. Use constants, never raw strings:

```php
// ✅ correct — caught by IDE and static analysis
DddPreset::ENTITY_MUST_BE_FINAL

// ❌ wrong — typo silently does nothing
'ddd.entity.must_be_fnal'
```

### Adopting existing projects

Fix reported violations where practical before reaching for a baseline. If the remaining findings are too large to resolve in one pass, generate a baseline to record the known violations:

```bash
vendor/bin/structarmed analyse --generate-baseline=structarmed-baseline.php
```

Then reference it from your config:

```php
return Architecture::define()
    ->baseline('structarmed-baseline.php')
    ->withPreset(Preset::PSR4());
```

Baseline entries are matched against future analysis results, so existing violations stay quiet while new violations still fail the run.

Do not use a baseline to hide issues you can fix now; treat it as a migration aid for legacy findings that should be reduced over time.
