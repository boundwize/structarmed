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

StructArmed is a configurable PHP architecture guard. Define your layers and rules, then run the analyser to catch boundary violations before they become project conventions.

<figure class="doc-screenshot">
    <img alt="StructArmed violation output" src="{{ '/assets/structarmed-showoff.png' | relative_url }}">
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
- [Custom Rules And Presets](custom-rules-and-presets/) covers project-specific checks and reusable architecture presets.
- [Presets](presets/) lists the included PSR, MVC, and DDD presets.
- [CLI](cli/) documents analyse, report, version, and baseline commands.
- [PHPUnit Extension](phpunit-extension/) explains how to fail your test suite on architecture violations.
- [Cache](cache/) covers analysis cache configuration.
- [Baselines](baselines/) covers legacy-project adoption without hiding new violations.
