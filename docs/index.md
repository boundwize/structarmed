---
title: Home
layout: default
nav_order: 1
---

<p align="center">
    <img src="https://github.com/user-attachments/assets/18024dc9-8658-40ca-abec-2df7b675a3b8" alt="StructArmed" width="260">
</p>

StructArmed is a configurable PHP architecture guard. Define your layers and rules, then run the analyser to catch boundary violations before they become project conventions.

## Contents
{: .no_toc }

1. TOC
{:toc}

## Why Use StructArmed

- Make architecture decisions executable, not just documented.
- Start with ready-made presets for common architecture styles.
- Tune, override, or skip individual preset rules in native PHP code.
- Catch boundary violations during local development and CI.

## Install

```bash
composer require --dev boundwize/structarmed
```

## Run Your First Check

```bash
vendor/bin/structarmed init --preset=psr4
vendor/bin/structarmed analyse
```

The `init` command writes `structarmed.php` to your project root. Edit that file to match your architecture, then run `analyse` whenever you want to enforce it.

## Where To Go Next

- [Quick Start](quick-start/) covers installation, initialization, and the available starter presets.
- [Configuration](configuration/) shows how to define custom layers, skips, rule replacements, and custom presets.
- [Layers and Rulesets](layers-and-rulesets/) explains path-based layers, namespace-based layers, and dependency rules.
- [Presets](presets/) lists the included PSR, MVC, and DDD presets.
- [CLI](cli/) documents analyse, report, version, and baseline commands.
- [PHPUnit Extension](phpunit-extension/) explains how to fail your test suite on architecture violations.
- [Cache and Baselines](cache-and-baselines/) covers cache directories and legacy-project adoption.
