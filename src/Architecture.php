<?php

declare(strict_types=1);

namespace Boundwize\StructArmed;

use Boundwize\StructArmed\Exception\RuleNotFoundException;
use Boundwize\StructArmed\Preset\PresetInterface;
use Boundwize\StructArmed\Rule\ProjectRuleInterface;
use Boundwize\StructArmed\Rule\RuleInterface;

use function array_filter;
use function array_merge;
use function is_int;
use function sprintf;

/**
 * Fluent architecture definition builder.
 *
 * Minimum config:
 *
 *   return Architecture::define()
 *       ->layer('Domain',         'src/Domain/')
 *       ->layer('Application',    'src/Application/')
 *       ->layer('Infrastructure', 'src/Infrastructure/');
 *
 * With preset:
 *
 *   return Architecture::define()
 *       ->layer('Domain',         'src/Domain/')
 *       ->layer('Application',    'src/Application/')
 *       ->layer('Infrastructure', 'src/Infrastructure/')
 *       ->withPreset(Preset::DDD());
 *
 * Override preset rules:
 *
 *   ->skipRule(DddPreset::DOMAIN_NO_BASE_EXCEPTION)
 *   ->replaceRule(DddPreset::ENTITY_MUST_BE_FINAL, new MustBeFinalRule(...))
 */
final class Architecture
{
    /** @var array<string, string|list<string>> name → path prefixes */
    private array $layers = [];

    /** @var array<string, RuleInterface|ProjectRuleInterface> key → rule */
    private array $rules = [];

    /** @var list<string> */
    private array $skipPaths = [];

    /** @var list<string> */
    private array $pendingSkips = [];

    /** @var list<string> */
    private array $skippedRuleKeys = [];

    /** @var array<string, list<string>> */
    private array $ruleSkipPaths = [];

    /**
     * Layers resolved by class-name regex patterns rather than file-system paths.
     *
     * @var array<string, array{pattern: string, excludePattern: string|null}>
     */
    private array $layerPatterns = [];

    /**
     * Allowed layer dependencies: layer name → list of layers it may depend on.
     * Any dependency that resolves to a layer NOT in the allowed list is a violation.
     * Layers absent from this map are not checked.
     *
     * @var array<string, list<string>>
     */
    private array $ruleset = [];

    /**
     * Per-class violation skips: class name → list of dependency class names to ignore.
     *
     * @var array<string, list<string>>
     */
    private array $classViolationSkips = [];

    /**
     * Path globs that are excluded from ruleset evaluation only.
     * Files matching these globs are still scanned for all other rules (e.g. PSR4).
     *
     * @var list<string>
     */
    private array $rulesetSkipPaths = [];

    private ?string $cacheDirectory = null;

    private ?string $baseline = null;

    private function __construct()
    {
    }

    public static function define(): self
    {
        return new self();
    }

    // -------------------------------------------------------------------------
    // Layer definition
    // -------------------------------------------------------------------------

    /**
     * @param string|list<string> $path
     */
    public function layer(string $name, string|array $path): self
    {
        $this->layers[$name] = $path;

        return $this;
    }

    /**
     * Define a layer by matching the fully-qualified class name against a regex pattern.
     *
     * Use this when architecture layers are expressed through namespace conventions
     * rather than directory structure.
     *
     * @param string      $name           Layer name.
     * @param string      $pattern        Regex matched against the FQN (e.g. '/^App\\HTTP\\.*$/').
     * @param string|null $excludePattern Optional regex; classes matching this are excluded
     *                                    from the layer even when $pattern matches.
     */
    public function layerPattern(string $name, string $pattern, ?string $excludePattern = null): self
    {
        $this->layerPatterns[$name] = [
            'pattern'        => $pattern,
            'excludePattern' => $excludePattern,
        ];

        return $this;
    }

    /**
     * Declare which layers each layer is allowed to depend on.
     *
     * Layers not listed as keys are not restricted; layers listed as keys may only
     * depend on the listed layers (dependencies that resolve to any other defined
     * layer are violations).
     *
     * @param array<string, list<string>> $ruleset  Map of layer name → allowed layer names.
     */
    public function ruleset(array $ruleset): self
    {
        $this->ruleset = $ruleset;

        return $this;
    }

    /**
     * Skip specific class-level dependency violations.
     *
     * When a class in one layer depends on a specific class in another layer, the
     * violation for that pair can be suppressed here without disabling the entire rule.
     *
     * @param string          $className    Fully-qualified class name of the violating class.
     * @param string|list<string> $dependencies  One or more FQN dependency class names to ignore.
     */
    public function skipClassViolation(string $className, string|array $dependencies): self
    {
        foreach ((array) $dependencies as $dep) {
            $this->classViolationSkips[$className][] = $dep;
        }

        return $this;
    }

    public function skipPath(string $path): self
    {
        $this->skipPaths[] = $path;

        return $this;
    }

    /**
     * @param string|list<string> $paths
     */
    public function skipPaths(string|array $paths): self
    {
        foreach ((array) $paths as $path) {
            $this->skipPaths[] = $path;
        }

        return $this;
    }

    /**
     * Exclude file paths from ruleset evaluation only.
     *
     * Files matching these glob patterns are still scanned for all other rules
     * (e.g. PSR4 namespace checks). Only the declarative ruleset layer-dependency
     * checks are skipped for matching files.
     *
     * @param string|list<string> $paths
     */
    public function skipPathsForRuleset(string|array $paths): self
    {
        foreach ((array) $paths as $path) {
            $this->rulesetSkipPaths[] = $path;
        }

        return $this;
    }

    public function skipRule(string $ruleKey): self
    {
        return $this->skipRules([$ruleKey]);
    }

    /**
     * @param string|list<string> $ruleKeys
     */
    public function skipRules(string|array $ruleKeys): self
    {
        foreach ((array) $ruleKeys as $ruleKey) {
            $this->registerPendingSkip($ruleKey);
        }

        return $this;
    }

    /**
     * @param array<int|string, string|list<string>> $paths
     */
    public function skip(array $paths): self
    {
        foreach ($paths as $ruleKey => $pathConfig) {
            if (is_int($ruleKey)) {
                foreach ((array) $pathConfig as $skip) {
                    $this->registerPendingSkip($skip);
                }

                continue;
            }

            foreach ((array) $pathConfig as $path) {
                $this->ruleSkipPaths[$ruleKey][] = $path;
            }
        }

        return $this;
    }

    public function cacheDirectory(?string $cacheDirectory): self
    {
        $this->cacheDirectory = $cacheDirectory;

        return $this;
    }

    public function baseline(?string $baseline): self
    {
        $this->baseline = $baseline;

        return $this;
    }

    /**
     * Preset management
     */
    public function withPreset(PresetInterface $preset): self
    {
        $preset->apply($this);

        return $this;
    }

    public function withPresets(PresetInterface ...$presets): self
    {
        foreach ($presets as $preset) {
            $this->withPreset($preset);
        }

        return $this;
    }

    // -------------------------------------------------------------------------
    // Rule management
    // -------------------------------------------------------------------------

    /**
     * Add a new custom rule.
     * If a rule with this key already exists it will be replaced.
     */
    public function rule(string $key, RuleInterface|ProjectRuleInterface $rule): self
    {
        $this->rules[$key] = $rule;
        $this->resolvePendingRuleSkip($key);

        return $this;
    }

    /**
     * Replace an existing rule by its constant key.
     * Throws RuleNotFoundException if the key does not exist —
     * use rule() to add new rules instead.
     *
     * @throws RuleNotFoundException
     */
    public function replaceRule(string $key, RuleInterface|ProjectRuleInterface $rule): self
    {
        if (! isset($this->rules[$key])) {
            throw new RuleNotFoundException(sprintf(
                'Cannot replace rule [%s] — rule not found. '
                . 'Did you forget to call withPreset() first, or is the key wrong?',
                $key
            ));
        }

        $this->rules[$key] = $rule;

        return $this;
    }

    // -------------------------------------------------------------------------
    // Accessors for the Analyser
    // -------------------------------------------------------------------------

    /** @return array<string, string|list<string>> */
    public function getLayers(): array
    {
        return $this->layers;
    }

    /**
     * @return array<string, array{pattern: string, excludePattern: string|null}>
     */
    public function getLayerPatterns(): array
    {
        return $this->layerPatterns;
    }

    /**
     * @return array<string, list<string>>
     */
    public function getRuleset(): array
    {
        return $this->ruleset;
    }

    /**
     * @return array<string, list<string>>
     */
    public function getClassViolationSkips(): array
    {
        return $this->classViolationSkips;
    }

    /**
     * @return list<string>
     */
    public function getRulesetSkipPaths(): array
    {
        return $this->rulesetSkipPaths;
    }

    /** @return array<string, RuleInterface|ProjectRuleInterface> */
    public function getRules(): array
    {
        return $this->rules;
    }

    /** @return list<string> */
    public function getSkipPaths(): array
    {
        return array_merge(
            $this->skipPaths,
            array_filter(
                $this->pendingSkips,
                fn(string $skip): bool => ! isset($this->rules[$skip])
            )
        );
    }

    /** @return array<string, list<string>> */
    public function getRuleSkipPaths(): array
    {
        return $this->ruleSkipPaths;
    }

    /** @return list<string> */
    public function getSkippedRuleKeys(): array
    {
        return $this->skippedRuleKeys;
    }

    public function getCacheDirectory(): ?string
    {
        return $this->cacheDirectory;
    }

    public function getBaseline(): ?string
    {
        return $this->baseline;
    }

    private function registerPendingSkip(string $skip): void
    {
        if (isset($this->rules[$skip])) {
            $this->skippedRuleKeys[] = $skip;

            return;
        }

        $this->pendingSkips[] = $skip;
    }

    private function resolvePendingRuleSkip(string $ruleKey): void
    {
        $pendingSkips = [];

        foreach ($this->pendingSkips as $pendingSkip) {
            if ($pendingSkip === $ruleKey) {
                $this->skippedRuleKeys[] = $pendingSkip;

                continue;
            }

            $pendingSkips[] = $pendingSkip;
        }

        $this->pendingSkips = $pendingSkips;
    }
}
