# StructArmed

<p align="center">
    <img src="https://github.com/user-attachments/assets/18024dc9-8658-40ca-abec-2df7b675a3b8" alt="StructArmed" width="300">
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

<p align="center">
    <img src="./docs/assets/structarmed-showoff.png" alt="StructArmed violation output">
</p>

## Documentation

The full documentation now lives in [docs/](docs/index.md) and is ready for GitHub Pages with the `just-the-docs` template.

- Documentation site: <https://boundwize.github.io/structarmed/>
- Local entry point: [docs/index.md](docs/index.md)

## Installation

```bash
composer require --dev boundwize/structarmed
```

## Quick Start

```bash
vendor/bin/structarmed init --preset=psr4
vendor/bin/structarmed analyse
```

See the [quick start guide](docs/quick-start.md) for every preset, the [configuration guide](docs/configuration.md) for project setup, and [custom rules and presets](docs/custom-rules-and-presets.md) for extension points.

## Contributing

Contributions are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) for setup, tooling, and pull request expectations.

## License

StructArmed is released under the [MIT License](LICENSE).
