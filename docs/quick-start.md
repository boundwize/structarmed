---
title: Quick Start
layout: default
nav_order: 2
---

# Quick Start

Install StructArmed as a development dependency, initialize a preset, then run the analyser.

## Contents
{: .no_toc }

1. TOC
{:toc}

## Installation

```bash
composer require --dev boundwize/structarmed
```

## Initialize A Preset

```bash
# Defaults to --preset=psr4
vendor/bin/structarmed init

# Verify source paths match composer.json PSR-4 mappings
vendor/bin/structarmed init --preset=psr4

# Enforce basic coding standard rules
vendor/bin/structarmed init --preset=psr1

# PSR-12 extends PSR-1 with explicit member visibility checks
vendor/bin/structarmed init --preset=psr12

# PSR-15 middleware and request handler interface checks
vendor/bin/structarmed init --preset=psr15

# Thin controllers, model/view/service layer rules
vendor/bin/structarmed init --preset=mvc

# Layer isolation and DDD naming conventions
vendor/bin/structarmed init --preset=ddd

# Enable every preset at once
vendor/bin/structarmed init --preset=all
```

The generated `structarmed.php` file lives in your project root.

## Analyse The Project

```bash
vendor/bin/structarmed analyse
```

The American spelling is also supported:

```bash
vendor/bin/structarmed analyze
```

If violations are found, StructArmed reports each one:

<figure class="doc-screenshot doc-screenshot--wide">
    <img alt="StructArmed violation output" src="{{ '/assets/structarmed-showoff.png' | relative_url }}">
</figure>

If everything passes, StructArmed prints a clean summary:

<figure class="doc-screenshot">
    <img alt="StructArmed clean analysis output" src="{{ '/assets/no-violation.png' | relative_url }}">
</figure>

## Default Configuration

```php
<?php

// structarmed.php
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\Preset;

return Architecture::define()
    ->withPreset(Preset::PSR4());
```

## Multiple Presets

```php
->withPresets(
    Preset::PSR4(),
    Preset::PSR1(),
    Preset::PSR12(),
    Preset::PSR15(),
    Preset::MVC(),
    Preset::DDD(),
)
```
