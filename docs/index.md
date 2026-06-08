---
title: StructArmed
nav_title: Home
description: Define layers, presets, and rules in PHP, then catch architecture violations before they become project conventions.
layout: default
nav_order: 1
---

<p align="center">
    <img src="https://github.com/user-attachments/assets/18024dc9-8658-40ca-abec-2df7b675a3b8" alt="StructArmed" width="260">
</p>

<p align="center">
    Configurable PHP architecture guards: define your layers and rules, then keep them enforced.
</p>

[![Latest Version](https://img.shields.io/github/release/boundwize/structarmed.svg?style=flat-square)](https://github.com/boundwize/structarmed/releases)
[![ci build](https://github.com/boundwize/structarmed/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/boundwize/structarmed/actions/workflows/ci.yml)
[![Code Coverage](https://codecov.io/gh/boundwize/structarmed/branch/main/graph/badge.svg)](https://codecov.io/gh/boundwize/structarmed)
[![PHPStan](https://img.shields.io/badge/style-level%20max-brightgreen.svg?style=flat-square&label=phpstan)](https://github.com/phpstan/phpstan)
[![Downloads](https://poser.pugx.org/boundwize/structarmed/downloads)](https://packagist.org/packages/boundwize/structarmed)

![Windows](https://img.shields.io/badge/Windows-supported-0078D6?logo=windows&logoColor=white&labelColor=555555)
![macOS](https://img.shields.io/badge/macOS-supported-C084FC?logo=apple&logoColor=white&labelColor=555555)
![Linux](https://img.shields.io/badge/Linux-supported-FCC624?logo=linux&logoColor=black&labelColor=555555)

StructArmed turns architecture decisions into executable checks. Start with presets for PSR, MVC, or DDD projects, then tune or extend the rules in native PHP.

<figure class="doc-screenshot">
    <img alt="StructArmed violation output" src="{{ '/assets/structarmed-showoff-editable.svg' | relative_url }}">
</figure>

## Contents
{: .no_toc }

1. TOC
{:toc}

## Why Use StructArmed

- Make architecture decisions executable, not just documented.
- Start with ready-made presets for common architecture styles.
- Tune, override, or skip individual preset rules in native PHP code.
- Catch boundary violations during local development and CI.

## Where To Go Next

- [Quick Start](quick-start/) covers installation, initialization, and the available starter presets.
- [Configuration](configuration/) shows how to define custom layers, skips, and preset rule replacements.
- [Layers and Rulesets](layers-and-rulesets/) explains path-based layers, namespace-based layers, and dependency rules.
- [Available Rules](available-rules/) lists every built-in rule class you can register, replace, or reuse.
- [Custom Rules And Presets](custom-rules-and-presets/) covers project-specific checks and reusable architecture presets.
- [Presets](presets/) lists the included PSR, MVC, and DDD presets.
- [CLI](cli/) documents analyse, report, version, and baseline commands.
- [PHPUnit Extension](phpunit-extension/) explains how to fail your test suite on architecture violations.
- [Cache](cache/) covers analysis cache configuration.
- [Baselines](baselines/) covers legacy-project adoption without hiding new violations.
