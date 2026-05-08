<?php

declare(strict_types=1);

namespace Boundwize\StructArmed;

use Boundwize\StructArmed\Exception\RuleNotFoundException;
use Boundwize\StructArmed\Preset\PresetInterface;
use Boundwize\StructArmed\Rule\RuleInterface;

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
    /** @var array<string, string> name → path */
    private array $layers = [];

    /** @var array<string, RuleInterface> key → rule */
    private array $rules = [];

    private function __construct() {}

    public static function define(): self
    {
        return new self();
    }

    // -------------------------------------------------------------------------
    // Layer definition
    // -------------------------------------------------------------------------

    public function layer(string $name, string $path): self
    {
        $this->layers[$name] = $path;

        return $this;
    }

    // -------------------------------------------------------------------------
    // Preset management
    // -------------------------------------------------------------------------

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
    public function rule(string $key, RuleInterface $rule): self
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
    public function replaceRule(string $key, RuleInterface $rule): self
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

    /** @return array<string, string> */
    public function getLayers(): array
    {
        return $this->layers;
    }

    /** @return array<string, RuleInterface> */
    public function getRules(): array
    {
        return $this->rules;
    }
}
