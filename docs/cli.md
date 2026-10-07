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

## Base Path

The project root defaults to the directory the command runs in. Pass `--basepath` when StructArmed is installed somewhere else, for example in a `tools/structarmed` directory with its own `composer.json`:

```text
composer.json
src/
tests/
tools/
└── structarmed/
    ├── composer.json
    ├── structarmed.php
    └── vendor/
```

```bash
cd tools/structarmed
vendor/bin/structarmed analyse --basepath=../../
```

`-d` is a short alias for `--basepath`:

```bash
vendor/bin/structarmed analyse -d ../../
```

Everything relative to the project root now resolves against the base path: layer paths such as `->layer('Config', 'src/ConfigProvider.php')`, scan paths given on the command line, the `composer.json` read by the composer rules and PSR-4 layers, the cache directory, and baseline paths. The config file is discovered in the current directory first, then in the base path; `--config` keeps pointing to a path relative to the current directory.

`--clear-cache` accepts the same option, so the cache of a project analysed through `--basepath` is cleared with:

```bash
vendor/bin/structarmed --basepath=../../ --clear-cache
```

Options may be given before or after the command.

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

## Progress Output

The console report shows a progress bar on stderr while files are parsed. Disable it for CI logs or when piping output:

```bash
vendor/bin/structarmed analyse --no-progress
```

The `json` and `github` reports never show a progress bar.

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
