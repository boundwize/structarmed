---
title: PHPUnit Extension
layout: default
nav_order: 9
---

# PHPUnit Extension
{: .no_toc }

Run architecture checks as part of your test suite.

## Contents
{: .no_toc }

1. TOC
{:toc}

## Register The Extension

```xml
<!-- phpunit.xml -->
<extensions>
    <bootstrap class="Boundwize\StructArmed\PHPUnit\StructArmedExtension"/>
</extensions>
```

## Behavior

Violations cause the test run to fail before any tests execute. This makes architecture checks part of the same feedback loop as your normal PHPUnit suite.

## Disable For One Run

Set `STRUCTARMED_DISABLED` to `1` to run PHPUnit without the architecture checks:

```bash
STRUCTARMED_DISABLED=1 vendor/bin/phpunit
```

The extension remains configured in `phpunit.xml`, but it does not analyse anything for that run. Other values do not disable StructArmed.

## When To Use It

Use the extension when a project already treats PHPUnit as the main local or CI verification command. If your CI pipeline separates static analysis and tests, running `vendor/bin/structarmed analyse` as a dedicated step may be clearer.
