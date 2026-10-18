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

Use `--no-cache` for a one-off run, such as a CI job that starts without a cache, or the first scan of a huge codebase. StructArmed then neither hashes the analysed files nor reads or writes any cache entry, so the run skips work that only a later run would benefit from.

```bash
vendor/bin/structarmed analyse --no-cache
```

The existing cache is left untouched. `--no-cache` cannot be combined with `--clear-cache`; clear the cache separately with `vendor/bin/structarmed --clear-cache`.
