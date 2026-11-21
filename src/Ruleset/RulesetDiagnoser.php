<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Ruleset;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Util\SourceLayerName;

use function sprintf;
use function str_starts_with;
use function substr;

/**
 * Inspects the configured ruleset as a dependency graph. Only the Architecture
 * is read: no source file is scanned or parsed.
 *
 * A plain `Layer` entry allows that layer alone; a `+Layer` entry also inherits
 * the layers it is allowed to depend on. A dependency that is merely reachable
 * through a plain entry is therefore not allowed by it, and is no issue.
 */
final readonly class RulesetDiagnoser
{
    /**
     * @return list<RulesetDiagnostic> In the order the ruleset declares its layers and their entries.
     */
    public function diagnose(Architecture $architecture): array
    {
        $ruleset       = $architecture->getRuleset();
        $definedLayers = $architecture->getLayers() + $architecture->getLayerPatterns();

        // The graph is built once: every layer with the dependencies it declares, each
        // one kept once and flagged true when a `+` entry inherits it.
        $declarations    = [];
        $duplicates      = [];
        $edges           = [];
        $inheritedLayers = [];

        foreach ($ruleset as $layer => $entries) {
            $declarations[$layer] = [];

            foreach ($entries as $entry) {
                $inherits   = str_starts_with($entry, '+');
                $dependency = $inherits ? substr($entry, 1) : $entry;

                if (isset($declarations[$layer][$dependency])) {
                    $duplicates[$layer][$dependency] = true;
                    $inherits                        = $inherits || $declarations[$layer][$dependency];
                }

                $declarations[$layer][$dependency] = $inherits;

                if ($inherits) {
                    $inheritedLayers[$dependency] = true;
                }

                // Same-layer dependencies are always allowed, so a layer naming itself adds no edge.
                // Neither does a name that no layer() or layerPattern() defines, as it matches no class,
                // unless it is a ruleset key inherited with `+`: that groups allowed layers under a name.
                if (
                    $dependency !== $layer
                    && ($this->isDefined($dependency, $definedLayers) || ($inherits && isset($ruleset[$dependency])))
                ) {
                    $edges[$layer][$dependency] = $inherits;
                }
            }
        }

        $diagnostics = [];

        foreach ($declarations as $layer => $declaredDependencies) {
            // Names the list a fix applies to, as the ruleset declares it: 'Layer' => [...].
            $dependencies = sprintf("the dependencies of layer '%s'", $layer);

            if (! $this->isDefined($layer, $definedLayers) && ! isset($inheritedLayers[$layer])) {
                $diagnostics[] = new RulesetDiagnostic(
                    RulesetDiagnostic::ERROR,
                    $layer,
                    '',
                    'Undefined layer',
                    sprintf("Define layer '%s', or remove '%s' from the ruleset", $layer, $layer),
                );
            }

            // An entry gets one fix at most: the first check that applies to it.
            foreach ($declaredDependencies as $dependency => $inherits) {
                $entry = $inherits ? '+' . $dependency : $dependency;

                if ($dependency === $layer) {
                    $diagnostics[] = new RulesetDiagnostic(
                        RulesetDiagnostic::WARNING,
                        $layer,
                        $entry,
                        'Same layer, always allowed',
                        sprintf("Remove '%s' from %s", $entry, $dependencies),
                    );

                    continue;
                }

                if (! isset($edges[$layer][$dependency])) {
                    $diagnostics[] = new RulesetDiagnostic(
                        RulesetDiagnostic::ERROR,
                        $layer,
                        $entry,
                        'Undefined layer',
                        isset($ruleset[$dependency])
                            ? sprintf("Replace '%s' with '+%s' in %s", $entry, $dependency, $dependencies)
                            : sprintf("Define layer '%s', or remove '%s' from %s", $dependency, $entry, $dependencies),
                    );

                    continue;
                }

                $inheritingEntry = $this->inheritingEntry($edges, $layer, $dependency, $inherits);

                if ($inheritingEntry !== null) {
                    $diagnostics[] = new RulesetDiagnostic(
                        RulesetDiagnostic::WARNING,
                        $layer,
                        $entry,
                        sprintf("Already inherited through '%s'", $inheritingEntry),
                        sprintf("Remove '%s' from %s", $entry, $dependencies),
                    );

                    // Every reported entry is taken out of the graph, so a later one is never
                    // called redundant because of it: all of them can be removed together.
                    unset($edges[$layer][$dependency]);

                    continue;
                }

                if (isset($duplicates[$layer][$dependency])) {
                    $diagnostics[] = new RulesetDiagnostic(
                        RulesetDiagnostic::WARNING,
                        $layer,
                        $entry,
                        'Listed more than once',
                        sprintf("Keep a single '%s' in %s", $entry, $dependencies),
                    );
                }
            }
        }

        return $diagnostics;
    }

    /**
     * A layer that the resolvers can assign to a class. `Source` is filled from
     * composer.json when the architecture leaves it out.
     *
     * @param array<string, mixed> $definedLayers
     */
    private function isDefined(string $layer, array $definedLayers): bool
    {
        return isset($definedLayers[$layer]) || SourceLayerName::matches($layer);
    }

    /**
     * Breadth-first search, through `+` entries only, for another entry of the
     * layer that already inherits the dependency, so without using the direct
     * entry between the two. Each layer is visited once, so cycles end the
     * search instead of extending it.
     *
     * @param array<string, array<string, bool>> $edges     layer → dependency → inherited with `+`
     * @param bool                               $inherited The dependency is declared as a `+` entry itself.
     * @return string|null The inheriting entry as the layer declares it: `+Cache`.
     */
    private function inheritingEntry(array $edges, string $from, string $to, bool $inherited): ?string
    {
        // Each visited layer, with the entry of the starting layer it was reached through.
        $entries = [$from => ''];
        $queue   = [$from];

        for ($index = 0; isset($queue[$index]); $index++) {
            $layer = $queue[$index];

            foreach ($edges[$layer] ?? [] as $dependency => $inherits) {
                if ($dependency === $to) {
                    // From the starting layer, this is the direct entry under evaluation. A `+` entry
                    // is only matched by another `+` entry, the one kind that inherits the same layers.
                    if ($layer === $from || ($inherited && ! $inherits)) {
                        continue;
                    }

                    return $entries[$layer];
                }

                // A plain entry does not pass on the layers its layer is allowed to depend on.
                if (! $inherits || isset($entries[$dependency])) {
                    continue;
                }

                $entries[$dependency] = $layer === $from ? '+' . $dependency : $entries[$layer];
                $queue[]              = $dependency;
            }
        }

        return null;
    }
}
