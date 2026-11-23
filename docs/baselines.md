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
Configuration: /path/to/project/structarmed.php

No violations found. (0.01s)
```

{: .important }
Baseline entries are matched against future analysis results, so existing violations stay quiet while new ones still fail the run. Treat the baseline as a migration aid for legacy findings, not as a way to silence issues you can fix now.

## Missing Baseline File

A configured baseline file that does not exist does not block the analysis for non-`json` reports. Violations are reported without baseline filtering, followed by a warning:

```text
Warning: Baseline file [structarmed-baseline.php] does not exist, violations are reported without baseline filtering.
```

The exit code still depends only on the reported violations.

The `json` report cannot carry a warning without breaking its output, so with `--report=json` a missing baseline file still stops the run:

```text
Error: Baseline file [structarmed-baseline.php] does not exist.
```

## Baseline Info

A baseline goes stale once baselined violations are fixed: their entries stay in the file. StructArmed does not warn about that by default, because a baseline can be kept as is on purpose, for example when it is shared between branches. Pass `--baseline-info` to see how the configured baseline matches the current analysis:

```bash
vendor/bin/structarmed analyse --baseline-info
```

A line is printed after the report:

```text
Baseline [structarmed-baseline.php]: 46 violations filtered out, 0 no longer found.
```

- `filtered out` is the number of violations the baseline kept out of the report.
- `no longer found` is the number of baselined violations the analysis did not produce anymore.

When `no longer found` is above `0`, the line turns into a warning, and regenerating the baseline with `--generate-baseline` drops those entries:

```text
Warning: Baseline [structarmed-baseline.php]: 46 violations filtered out, 2 no longer found.
```

The `no longer found` count needs a full analysis. When paths are passed, as in `analyse src --baseline-info`, baselined violations of the files left out cannot be told from fixed ones, so only the `filtered out` count is printed.

Without a configured baseline the line is `Baseline: not configured.`. The option does not change the exit code.

The line is printed for the `console`, `summary`, and `github` reports. The `json` report cannot carry an extra line without breaking its output, so it carries the same numbers under a `baseline` key:

```bash
vendor/bin/structarmed analyse --report=json --baseline-info
```

```json
{
    "violations": [],
    "baseline": {
        "file": "structarmed-baseline.php",
        "filtered_out": 46,
        "no_longer_found": 2
    },
    "total": 0,
    "passed": true,
    "elapsed": 0.34
}
```

`no_longer_found` is `null` when paths are passed, and `file` is `null` without a configured baseline. The key is left out without `--baseline-info`.

The [PHPUnit extension](../phpunit-extension/#baseline-info) prints the same line through its `baseline-info` parameter.

## When To Use A Baseline

Use a baseline when a legacy project has known architecture violations that cannot be fixed in one pass.

Do not use a baseline to silence new problems that can be fixed immediately.
