---
title: Layers And Rulesets
layout: default
nav_order: 4
---

# Layers And Rulesets
{: .no_toc }

Layers tell StructArmed where classes belong. Rulesets tell StructArmed which layer dependencies are allowed.

## Contents
{: .no_toc }

1. TOC
{:toc}

## Path-Based Layers

Each class is assigned a layer based on which registered `layer()` path its file falls under. No class attributes are needed.

```php
// Files under src/Domain/ resolve to layer 'Domain'.
->layer('Domain', 'src/Domain/')

// Files under src/Application/ resolve to layer 'Application'.
->layer('Application', 'src/Application/')

// Files under src/Infrastructure/ resolve to layer 'Infrastructure'.
->layer('Infrastructure', 'src/Infrastructure/')
```

## Namespace-Based Layers

When your architecture is expressed through namespace conventions rather than directory structure, use `layerPattern()` to resolve layers by matching the fully-qualified class name against a regex.

```php
return Architecture::define()
    ->layerPattern('API',    '/^App\\\\API\\\\.*$/')
    ->layerPattern('HTTP',   '/^App\\\\HTTP\\\\.*$/')
    ->layerPattern('Router', '/^App\\\\Router\\\\.*$/');
```

## Declarative Rulesets

Once layers are defined, declare which layers each layer is allowed to depend on. Any dependency that resolves to a layer outside the allowed list is a violation.

```php
return Architecture::define()
    ->layerPattern('API',      '/^App\\\\API\\\\.*$/')
    ->layerPattern('HTTP',     '/^App\\\\HTTP\\\\.*$/')
    ->layerPattern('Database', '/^App\\\\Database\\\\.*$/')
    ->ruleset([
        'API'      => ['HTTP'],     // API may only depend on HTTP.
        'HTTP'     => ['Database'], // HTTP may only depend on Database.
        'Database' => [],           // Database may not depend on any layer.
    ]);
```

Layers absent from the ruleset keys are not checked. Dependencies on external, non-registered classes are always allowed.

Same-layer dependencies are always allowed regardless of the ruleset.

## Inheriting Allowed Layers

Use the `+` prefix to merge a layer's allowed dependencies into another layer's allowed list.

```php
->ruleset([
    'API'        => ['Format'],
    'Controller' => ['Validation'],
    'RESTful'    => ['+API', '+Controller'],
])
```

Each `+LayerName` entry expands to that layer itself plus all layers it is allowed to depend on.

```diff
-'RESTful' => ['API', 'Format', 'Controller', 'Validation'],
+'RESTful' => ['+API', '+Controller'],
```

When the allowed layers of `API` or `Controller` change, `RESTful` picks them up automatically. Unknown `+` references expand to nothing silently.

## Multiple Layer Patterns

You can assign a layer using multiple regexes.

```php
return Architecture::define()
    ->layerPattern('Service', [
        '/^App\\\\Service\\\\.*$/',
        '/^App\\\\Application\\\\.*Service$/',
    ]);
```

## Excluding From Layer Patterns

An optional third argument excludes classes whose FQN matches one or more regexes, even when the layer pattern matches.

Use a single exclude regex when one class or namespace branch should not belong to a broader layer. In this example, `App\HTTP\URI` matches the broad HTTP pattern, but it is excluded from `HTTP` and then registered as its own `URI` layer.

```php
// HTTP layer: includes everything under App\HTTP\, except App\HTTP\URI.
->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/', '/^App\\\\HTTP\\\\URI$/')
->layerPattern('URI',  '/^App\\\\HTTP\\\\URI$/')
```

Use an array of exclude regexes when several class-name patterns should be omitted from the same broad layer.

```php
->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/', [
    '/Exception$/',
    '/^App\\\\HTTP\\\\URI$/',
])
```

With the configuration above, classes such as `App\HTTP\Request` still resolve to `HTTP`, while `App\HTTP\RequestException` and `App\HTTP\URI` do not.

## Skipping Class-Level Violations

When a specific class-to-class dependency is a known exception, suppress it without disabling the whole layer rule.

```php
->skipClassViolation('App\\HTTP\\ResponseTrait', [
    'App\\Pager\\PagerInterface',
])
->skipClassViolation('App\\Log\\ChromeLoggerHandler', 'App\\HTTP\\ResponseInterface')
```

The first argument is the fully-qualified violating class name. The second argument is one or more dependency FQNs to ignore for that class.

## Excluding Paths From Ruleset Checks Only

Test files often cross layer boundaries by design. Use `skipPathsForRuleset()` to exclude paths from ruleset evaluation while still scanning them for all other rules, such as PSR-4 namespace checks.

```php
return Architecture::define()
    ->withPresets(Preset::PSR4())
    ->layerPattern('HTTP',     '/^App\\\\HTTP\\\\.*$/')
    ->layerPattern('Database', '/^App\\\\Database\\\\.*$/')
    ->ruleset(['HTTP' => [], 'Database' => ['HTTP']])
    ->skipPathsForRuleset(['*tests*', '*fixtures*']);
```

This is different from `skipPaths()` and `skipPath()`, which exclude files from all analysis.
