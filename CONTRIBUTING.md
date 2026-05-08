# Contributing

Contributions are welcome via Pull Requests on [GitHub](https://github.com/boundwize/structarmed).

## Setup

Fork the repository on GitHub, then clone your fork:

```bash
git clone https://github.com/<your-username>/structarmed.git
cd structarmed
git remote add upstream https://github.com/boundwize/structarmed.git
composer install
```

## Tooling

| Command | Description |
|---|---|
| `composer test` | Run the test suite |
| `composer cs-check` | Check coding standard |
| `composer cs-fix` | Fix coding standard violations |
| `composer phpstan` | Run static analysis |
| `composer rector` | Check for Rector suggestions (dry-run) |

All checks must pass before a PR will be merged. CI runs against PHP 8.2, 8.3, and 8.4 on Linux, macOS, and Windows.

## Adding a new Rule

1. Create your rule class in `src/Rule/Rules/{Category}/YourRule.php`
2. Implement `RuleInterface` — `appliesTo()` and `evaluate()`
3. Add a test in `tests/Rule/{Category}/YourRuleTest.php`
4. Optionally add it to a relevant preset

## Adding a new Preset

1. Create `src/Preset/Presets/YourPreset.php` implementing `PresetInterface`
2. Define public constants for every rule key
3. Add a factory method to `src/Preset/Preset.php`
4. Add tests in `tests/Preset/`
5. Document in README
