<?php

declare(strict_types=1);

namespace Boundwize\StructArmed;

use Boundwize\StructArmed\Exception\RuleNotFoundException;
use Boundwize\StructArmed\Preset\PresetInterface;
use Boundwize\StructArmed\Rule\ProjectRuleInterface;
use Boundwize\StructArmed\Rule\RuleInterface;

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
 *   ->withoutRule(DddPreset::DOMAIN_NO_BASE_EXCEPTION)
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

    /** @var array<string, list<string>> */
    private array $ruleSkipPaths = [];

    private ?string $cacheDirectory = null;

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

    public function skipPath(string $path): self
    {
        return $this->skip([$path]);
    }

    /**
     * @param string|list<string> $paths
     */
    public function skipPaths(string|array $paths): self
    {
        return $this->skip((array) $paths);
    }

    /**
     * @param array<int|string, string|list<string>> $paths
     */
    public function skip(array $paths): self
    {
        foreach ($paths as $ruleKey => $pathConfig) {
            if (is_int($ruleKey)) {
                foreach ((array) $pathConfig as $path) {
                    $this->skipPaths[] = $path;
                }

                continue;
            }

            foreach ((array) $pathConfig as $path) {
                $this->ruleSkipPaths[$ruleKey][] = $path;
            }
        }

        return $this;
    }

    public function cacheDirectory(string $cacheDirectory): self
    {
        $this->cacheDirectory = $cacheDirectory;

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

    /**
     * Remove an existing rule by its constant key.
     * Throws RuleNotFoundException if the key does not exist.
     *
     * @throws RuleNotFoundException
     */
    public function withoutRule(string $key): self
    {
        if (! isset($this->rules[$key])) {
            throw new RuleNotFoundException(sprintf(
                'Cannot remove rule [%s] — rule not found.',
                $key
            ));
        }

        unset($this->rules[$key]);

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

    /** @return array<string, RuleInterface|ProjectRuleInterface> */
    public function getRules(): array
    {
        return $this->rules;
    }

    /** @return list<string> */
    public function getSkipPaths(): array
    {
        return $this->skipPaths;
    }

    /** @return array<string, list<string>> */
    public function getRuleSkipPaths(): array
    {
        return $this->ruleSkipPaths;
    }

    public function getCacheDirectory(): ?string
    {
        return $this->cacheDirectory;
    }
}
