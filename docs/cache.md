---
title: Cache
layout: default
nav_order: 10
---

# Cache
{: .no_toc }

Configure where StructArmed stores analysis cache data.

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

Relative cache directories are resolved from the project root.

## Config-Specific Cache

When a custom config defines its own cache directory, both `analyse` and `--clear-cache` use that same directory.

```bash
vendor/bin/structarmed analyse --config=path/to/structarmed.php
```

## Clearing Cache

Use `--clear-cache` when you need to force StructArmed to discard cached analysis data before running again.

```bash
vendor/bin/structarmed analyse --clear-cache
```

You can also clear cache directly:

```bash
vendor/bin/structarmed --clear-cache
```

## Disabling Cache

Use `--no-cache` to run analysis without caching. StructArmed skips file hashing and neither reads nor writes cache entries. Skipping this work can make analysis faster on very huge codebases.

```bash
vendor/bin/structarmed analyse --no-cache
```

{: .important }
Keep in mind that every run with `--no-cache` repeats the full analysis, so similar runs will take roughly the same amount of time. With caching enabled, later runs can reuse cached results and finish faster.

The existing cache is left untouched. `--no-cache` cannot be combined with `--clear-cache`; clear the cache separately with `vendor/bin/structarmed --clear-cache`.
