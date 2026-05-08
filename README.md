# structarmed

<img width="1600" height="420" alt="Image" src="https://github.com/user-attachments/assets/62b0baa3-d81a-44ec-bd5a-16bb4c5c97f8" />

<br />

[![Latest Version](https://img.shields.io/github/release/boundwize/structarmed.svg?style=flat-square)](https://github.com/boundwize/structarmed/releases)
[![ci build](https://github.com/boundwize/structarmed/workflows/ci%20build/badge.svg)](https://github.com/boundwize/structarmed/actions)
[![Code Coverage](https://codecov.io/gh/boundwize/structarmed/branch/main/graph/badge.svg)](https://codecov.io/gh/boundwize/structarmed)
[![PHPStan](https://img.shields.io/badge/style-level%20max-brightgreen.svg?style=flat-square&label=phpstan)](https://github.com/phpstan/phpstan)
[![Downloads](https://poser.pugx.org/boundwize/structarmed/downloads)](https://packagist.org/packages/boundwize/structarmed)

Configurable PHP architecture guards — define your layers and rules, then keep them enforced.

## Installation

```bash
composer require --dev boundwize/structarmed
```

## Quick start

```bash
vendor/bin/structarmed init
vendor/bin/structarmed init --preset=ddd
vendor/bin/structarmed init --preset=mvc
vendor/bin/structarmed init --preset=psr4
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
// structarmed.php
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\Preset;

return Architecture::define()
    ->withPreset(Preset::PSR4());
```

### Multiple presets

```php
->withPresets(Preset::PSR4(), Preset::DDD(), Preset::MVC())
```

### Custom layers and rules

```php
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Rules\Layer\MayNotDependOnRule;
use Boundwize\StructArmed\Rule\Rules\Method\MustHaveReturnTypeRule;

return Architecture::define()
    ->layer('Domain', 'src/Domain/')
    ->layer('Application', 'src/Application/')
    ->layer('Infrastructure', 'src/Infrastructure/')
    ->skip([
        'tests/Fixtures/',
        'var/cache/*',
        DddPreset::ENTITY_MUST_BE_FINAL => ['src/Legacy/'],
    ])
    ->rule(
        'domain.must_not_depend_on_infrastructure',
        new MayNotDependOnRule(from: 'Domain', to: 'Infrastructure', toPath: 'Infrastructure')
    )
    ->rule(
        'domain.public_methods_must_have_return_types',
        new MustHaveReturnTypeRule(layer: 'Domain')
    );
```

### Override preset rules

Use rule key constants — never raw strings:

```php
use Boundwize\StructArmed\Preset\Presets\DddPreset;

return Architecture::define()
    ->layer('Domain',         'src/Domain/')
    ->layer('Application',    'src/Application/')
    ->layer('Infrastructure', 'src/Infrastructure/')
    ->withPreset(Preset::DDD())

    // Remove a rule entirely
    ->withoutRule(DddPreset::DOMAIN_NO_BASE_EXCEPTION)

    // Replace a rule with a different configuration
    ->replaceRule(
        DddPreset::ENTITY_MUST_BE_FINAL,
        new MustBeFinalRule(layer: 'Domain', classNamePattern: '/Entity$|Aggregate$/')
    )

    // Add your own custom rule
    ->rule(
        'our.handlers.must_be_in_application',
        new NamingConventionRule(
            classNamePattern: '/Handler$/',
            mustBeInLayer: 'Application'
        )
    );
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

->withPreset(Preset::PSR4(
    sourcePaths: ['src/', 'tests/'], // default: read composer.json PSR-4 paths
))
```

## Available presets

| Preset | Rules |
|---|---|
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

## CLI

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

# Generate initial config
vendor/bin/structarmed init
vendor/bin/structarmed init --preset=ddd
vendor/bin/structarmed init --preset=mvc
vendor/bin/structarmed init --preset=psr4
vendor/bin/structarmed init --preset=all
```

## Layer resolution

Layers are resolved by file path — no attributes needed on classes:

```
src/Domain/     → 'Domain'
src/Application/ → 'Application'
src/Infrastructure/ → 'Infrastructure'
```

## Rule key constants

Every preset rule has a public constant. Use constants, never raw strings:

```php
// ✅ correct — caught by IDE and static analysis
DddPreset::ENTITY_MUST_BE_FINAL

// ❌ wrong — typo silently does nothing
'ddd.entity.must_be_fnal'
```

## Requirements

- PHP 8.2 or higher
- `nikic/php-parser` ^5.0

## License

[MIT](LICENSE)
