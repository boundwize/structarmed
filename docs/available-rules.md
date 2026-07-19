---
title: Available Rules
layout: default
nav_order: 5
---

# Available Rules
{: .no_toc }

StructArmed ships rule classes you can register directly in `structarmed.php`, replace inside presets, or reuse from custom presets.

## Contents
{: .no_toc }

1. TOC
{:toc}

## Using Built-In Rules Directly

Register built-in rules with `rule()` and give each rule a stable project-specific key.

```php
<?php

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeFinalRule;
use Boundwize\StructArmed\Rule\Rules\Usage\MayNotUseClassRule;

return Architecture::define()
    ->layer('Domain', 'src/Domain/')
    ->rule(
        'domain.entities_must_be_final',
        new MustBeFinalRule(layer: 'Domain', classNamePattern: '/Entity$/')
    )
    ->rule(
        'domain.must_not_use_datetime',
        new MayNotUseClassRule(layer: 'Domain', forbiddenClass: DateTime::class)
    );
```

Class, method, layer, and usage rules evaluate classes already assigned to layers. Composer and file rules evaluate project-level structure, such as `composer.json` PSR-4 mappings or source file contents.

## Composer And PSR-4 Rules

Namespace: `Boundwize\StructArmed\Rule\Rules\Composer`.

| Rule | Constructor | Checks |
|---|---|---|
| `Psr4DirectoryExistsRule` | `new Psr4DirectoryExistsRule()` | `composer.json` exists, is valid JSON, and every PSR-4 source path exists on disk. Supports `--fix` by removing mappings for missing directories. |
| `Psr4EmptyNamespacePrefixRule` | `new Psr4EmptyNamespacePrefixRule()` | `autoload` and `autoload-dev` PSR-4 mappings do not use an empty namespace prefix. |
| `Psr4NamespaceRule` | `new Psr4NamespaceRule(layer: 'Source')` | A class name matches the namespace expected from its PSR-4 path. |
| `Psr4RootPathRule` | `new Psr4RootPathRule()` | PSR-4 mappings do not point directly to the project root. |
| `Psr4SourcePathsRule` | `new Psr4SourcePathsRule(sourcePaths: ['src/', 'tests/'])` | Configured source paths are present in `composer.json` PSR-4 mappings. |
{: .rule-table }

## File Rules

Namespace: `Boundwize\StructArmed\Rule\Rules\File`.

| Rule | Constructor | Checks |
|---|---|---|
| `Psr1PhpTagsRule` | `new Psr1PhpTagsRule(sourcePaths: ['src/'])` | PHP files use only `<?php` and `<?=` tags. Supports `--fix`. |
| `Psr1SymbolsOrSideEffectsRule` | `new Psr1SymbolsOrSideEffectsRule(sourcePaths: ['src/'])` | A file declares symbols or causes side effects, but does not do both. |
| `Psr1ValidUtf8Rule` | `new Psr1ValidUtf8Rule(sourcePaths: ['src/'])` | PHP files use valid UTF-8 encoding. |
| `Psr1Utf8WithoutBomRule` | `new Psr1Utf8WithoutBomRule(sourcePaths: ['src/'])` | PHP files do not start with a byte order mark. Supports `--fix`. |
{: .rule-table }

Pass `sourcePaths: null` or omit it to let the rule read PSR-4 paths from `composer.json`.

## Class Rules

Namespace: `Boundwize\StructArmed\Rule\Rules\Class_`.

| Rule | Constructor | Checks |
|---|---|---|
| `ClassConstantNameMustBeUpperCaseRule` | `new ClassConstantNameMustBeUpperCaseRule(layer: 'Domain')` | Class constants use upper case with underscore separators. |
| `ClassImplementingInterfaceMustHaveSuffixRule` | `new ClassImplementingInterfaceMustHaveSuffixRule(layer: 'HTTP', interface: MiddlewareInterface::class, suffix: 'Middleware')` | Classes implementing a specific interface use the required suffix. |
| `ClassNameMustBeStudlyCapsRule` | `new ClassNameMustBeStudlyCapsRule(layer: 'Source')` | Class names use StudlyCaps. |
| `ClassNameMustHaveSuffixRule` | `new ClassNameMustHaveSuffixRule(layer: 'Controller', suffix: 'Controller')` | Classes in a layer have the required suffix. |
| `ClassNameMustNotHavePrefixRule` | `new ClassNameMustNotHavePrefixRule(layer: 'Model', prefix: 'Model')` | Classes in a layer do not use a forbidden prefix. |
| `MaxDependencyCountRule` | `new MaxDependencyCountRule(layer: 'Controller', maxCount: 5)` | Constructor dependency count stays below the configured limit. |
| `MayNotImplementInterfaceRule` | `new MayNotImplementInterfaceRule(layer: 'Domain', interface: JsonSerializable::class)` | Classes in a layer do not implement a forbidden interface. |
| `MustBeFinalRule` | `new MustBeFinalRule(layer: 'Domain', classNamePattern: '/Entity$/')` | Matching classes in a layer are declared `final`. Classes extended by another scanned class are skipped (making them `final` would break the child). Supports `--fix`. |
| `MustBeInterfaceRule` | `new MustBeInterfaceRule(layer: 'Contract', classNamePattern: '/Interface$/')` | Matching declarations in a layer are interfaces. |
| `MustDeclareConstantVisibilityRule` | `new MustDeclareConstantVisibilityRule(layer: 'Source')` | Class constants declare `public`, `protected`, or `private`. Supports `--fix`. |
| `MustDeclareMethodVisibilityRule` | `new MustDeclareMethodVisibilityRule(layer: 'Source')` | Methods declare `public`, `protected`, or `private`. Supports `--fix`. |
| `MustDeclarePropertyVisibilityRule` | `new MustDeclarePropertyVisibilityRule(layer: 'Source')` | Properties declare `public`, `protected`, or `private`. Supports `--fix`. |
| `MustImplementInterfaceRule` | `new MustImplementInterfaceRule(layer: 'HTTP', interface: RequestHandlerInterface::class, classNamePattern: '/Handler$/')` | Matching classes implement a required interface. |
| `NamingConventionRule` | `new NamingConventionRule(classNamePattern: '/Repository$/', mustBeInLayer: 'Infrastructure')` | Classes matching a name pattern live in the expected layer. |
{: .rule-table }

`classNamePattern` and `excludePattern` are regular expressions matched against the fully-qualified class name.

`Psr4DirectoryExistsRule`, `Psr1PhpTagsRule`, `Psr1Utf8WithoutBomRule`, `MustBeFinalRule`, `MustDeclareConstantVisibilityRule`, `MustDeclareMethodVisibilityRule`, and `MustDeclarePropertyVisibilityRule` implement `Boundwize\StructArmed\Rule\FixableInterface`, so StructArmed can automatically remove PSR-4 mappings for missing directories, normalize invalid PHP opening tags, remove UTF-8 byte order marks, add the `final` class modifier, and add missing constant, method, or property visibility modifiers when you run `vendor/bin/structarmed analyse --fix`.

## Layer Rules

Namespace: `Boundwize\StructArmed\Rule\Rules\Layer`.

| Rule | Constructor | Checks |
|---|---|---|
| `MayNotDependOnRule` | `new MayNotDependOnRule(from: 'Domain', to: 'Infrastructure', toPath: 'Infrastructure')` | Classes in one layer do not depend on classes from a forbidden layer. |
{: .rule-table }

`toPath` is optional. It is only needed when the forbidden layer name differs from the namespace or path segment that should be matched.

## Method Rules

Namespace: `Boundwize\StructArmed\Rule\Rules\Method`.

| Rule | Constructor | Checks |
|---|---|---|
| `MaxCyclomaticComplexityRule` | `new MaxCyclomaticComplexityRule(layer: 'Controller', maxComplexity: 5)` | Method cyclomatic complexity stays below the configured limit. |
| `MaxMethodLengthRule` | `new MaxMethodLengthRule(layer: 'Controller', maxLines: 30)` | Method line count stays below the configured limit. |
| `MethodNameMustBeCamelCaseRule` | `new MethodNameMustBeCamelCaseRule(layer: 'Source')` | Method names use camelCase. Magic methods are ignored. |
| `MustHaveReturnTypeRule` | `new MustHaveReturnTypeRule(layer: 'Domain', classNamePattern: '/Service$/')` | Public non-constructor methods declare a return type. |
{: .rule-table }

## Usage Rules

Namespace: `Boundwize\StructArmed\Rule\Rules\Usage`.

| Rule | Constructor | Checks |
|---|---|---|
| `MayNotCallFunctionRule` | `new MayNotCallFunctionRule(layer: 'Domain', function: 'header')` | Classes in a layer do not call a forbidden function. |
| `MayNotUseClassRule` | `new MayNotUseClassRule(layer: 'Domain', forbiddenClass: DateTime::class)` | Classes in a layer do not depend on a forbidden class. |
| `MayNotUseLanguageConstructRule` | `new MayNotUseLanguageConstructRule(layer: 'Domain', construct: 'echo')` | Classes in a layer do not use a forbidden language construct. |
| `MayNotUseNamespaceRule` | `new MayNotUseNamespaceRule(layer: 'Domain', forbiddenNamespace: 'Doctrine\\ORM\\')` | Classes in a layer do not depend on a forbidden namespace. |
| `MayNotUseSuperglobalsRule` | `new MayNotUseSuperglobalsRule(layer: 'Controller')` | Classes in a layer do not access superglobals directly. |
{: .rule-table }

`MayNotUseClassRule` and `MayNotUseNamespaceRule` also accept `classNamePattern` when only matching classes should be checked.

`MayNotUseLanguageConstructRule` accepts one of the following `construct` names: `echo`, `print`, `eval`, `isset`, `empty`, `unset`, `list`, `exit`, `die`, `include`, `include_once`, `require`, `require_once`. `die` is a pure alias of `exit`, so banning either spelling catches both. The `include` / `include_once` / `require` / `require_once` constructs are distinct and are matched exactly.
