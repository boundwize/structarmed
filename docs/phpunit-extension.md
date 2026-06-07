---
title: PHPUnit Extension
layout: default
nav_order: 9
---

# PHPUnit Extension

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

## When To Use It

Use the extension when a project already treats PHPUnit as the main local or CI verification command. If your CI pipeline separates static analysis and tests, running `vendor/bin/structarmed analyse` as a dedicated step may be clearer.
