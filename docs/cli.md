---
title: CLI
layout: default
nav_order: 8
---

# CLI
{: .no_toc }

StructArmed provides commands for initialization, analysis, reports, baselines, and version output.

## Contents
{: .no_toc }

1. TOC
{:toc}

## Init Commands

```bash
vendor/bin/structarmed init
vendor/bin/structarmed init --preset=psr4
vendor/bin/structarmed init --preset=psr1
vendor/bin/structarmed init --preset=psr12
vendor/bin/structarmed init --preset=psr15
vendor/bin/structarmed init --preset=mvc
vendor/bin/structarmed init --preset=ddd
vendor/bin/structarmed init --preset=yagni
vendor/bin/structarmed init --preset=codequality
vendor/bin/structarmed init --preset=all
```

## Analyse Commands

```bash
# Analyse with default config discovery.
vendor/bin/structarmed analyse
vendor/bin/structarmed analyze

# Analyse only specific paths.
vendor/bin/structarmed analyse src
vendor/bin/structarmed analyze src tests

# Custom config path.
vendor/bin/structarmed analyse --config=path/to/structarmed.php
vendor/bin/structarmed analyze --config=path/to/structarmed.php
```

## Auto-Fix Violations

Use `--fix` to automatically apply fixes for violations produced by rules that implement `Boundwize\StructArmed\Rule\FixableInterface`.

```bash
# Apply available fixes, then print the updated report.
vendor/bin/structarmed analyse --fix

# Fix only a subset of paths.
vendor/bin/structarmed analyze src --fix
```

When a violation is fixable, the console report adds a hint telling you to rerun the command with `--fix`. Rules that do not implement `FixableInterface` are still reported, but are skipped by the fixer pass.

## Reports

```bash
# Console output is the default.
vendor/bin/structarmed analyse

# JSON output for CI tools.
vendor/bin/structarmed analyse --report=json
vendor/bin/structarmed analyze --report=json

# GitHub Actions annotations.
vendor/bin/structarmed analyse --report=github
```

The `github` report prints one `::error file=...,line=...,title=...::message` workflow command per violation, so GitHub Actions shows each violation inline on the pull request. File paths are relative to the project root. The console report follows the workflow commands, so an annotation's "View details" link lands on a readable job log.

The `github` report always exits with `0`: violations surface as annotations instead of failing the job. Use the `console` or `json` report when the job must fail on violations.

## Parallel Processing

StructArmed runs in parallel by default. Disable parallel processing when debugging worker issues.

```bash
vendor/bin/structarmed analyse --disable-parallel
```

## Version Commands

```bash
vendor/bin/structarmed --version
vendor/bin/structarmed -V
```
