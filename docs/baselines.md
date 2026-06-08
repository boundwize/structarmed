---
title: Baselines
layout: default
nav_order: 11
---

# Baselines
{: .no_toc }

Use a baseline to adopt StructArmed gradually in an existing project without hiding new violations.

## Contents
{: .no_toc }

1. TOC
{:toc}

## Adopting Existing Projects

Fix reported violations where practical before reaching for a baseline. If the remaining findings are too large to resolve in one pass, generate a baseline to record the known violations.

```bash
vendor/bin/structarmed analyse --generate-baseline=structarmed-baseline.php
```

Then reference it from your config:

```php
return Architecture::define()
    ->baseline('structarmed-baseline.php')
    ->withPreset(Preset::PSR4());
```

Running `vendor/bin/structarmed analyse` will now pass as long as no new violations are introduced.

```bash
vendor/bin/structarmed analyse

StructArmed {version} - Architecture Enforcement
================================================

No violations found. (0.01s)
```

{: .important }
Baseline entries are matched against future analysis results, so existing violations stay quiet while new ones still fail the run. Treat the baseline as a migration aid for legacy findings, not as a way to silence issues you can fix now.

## When To Use A Baseline

Use a baseline when a legacy project has known architecture violations that cannot be fixed in one pass.

Do not use a baseline to silence new problems that can be fixed immediately.
