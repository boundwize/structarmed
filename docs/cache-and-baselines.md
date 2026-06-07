---
title: Cache And Baselines
layout: default
nav_order: 9
---

# Cache And Baselines

Configure analysis caching and adopt StructArmed gradually in existing projects.

## Contents
{: .no_toc }

1. TOC
{:toc}

## Cache Directory

StructArmed stores its analysis cache in the system temp directory by default. You can configure a project cache directory.

```php
<?php

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\Preset;

return Architecture::define()
    ->cacheDirectory('var/cache/structarmed')
    ->withPreset(Preset::PSR4());
```

Relative cache directories are resolved from the project root. `--config` also controls the cache directory used by `analyse` and `--clear-cache`.

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
