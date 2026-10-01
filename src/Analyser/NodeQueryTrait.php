<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use function in_array;
use function rtrim;
use function str_starts_with;
use function strcasecmp;

/**
 * Query helpers shared by {@see ClassNode}, {@see AnonymousClassNode},
 * {@see FunctionNode}, and {@see AnonymousFunctionNode}. All four nodes carry
 * the same body-level facts — layers, dependencies, function calls,
 * superglobals, language constructs — so rules can ask the same questions of
 * a function body that they ask of a class-like.
 *
 * @internal
 *
 * @property list<string> $layers All layer names this node belongs to; assigned once in each node's constructor
 * @property-read list<string> $dependencies       Fully-qualified class, function, or constant dependencies
 * @property      string[]     $functionCalls      Functions called within this node
 * @property-read string[]     $superglobals       Superglobals accessed ($_GET, $_POST, etc.)
 * @property-read string[]     $languageConstructs Language constructs used (exit, die, etc.)
 */
trait NodeQueryTrait
{
    public function isInLayer(string $layer): bool
    {
        return in_array($layer, $this->layers, true);
    }

    public function dependsOn(string $dependency, bool $isCaseSensitive = true): bool
    {
        if ($isCaseSensitive) {
            return in_array($dependency, $this->dependencies, true);
        }

        foreach ($this->dependencies as $existingDependency) {
            if (strcasecmp($existingDependency, $dependency) === 0) {
                return true;
            }
        }

        return false;
    }

    public function dependsOnNamespace(string $namespace): bool
    {
        $prefix = rtrim($namespace, '\\') . '\\';

        foreach ($this->dependencies as $dependency) {
            if (str_starts_with($dependency, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Replaces the function calls once the analyser knows every function: an
     * unqualified call in a namespace to a function not declared in the same
     * file is collected as a fallback marker until then.
     *
     * @param string[] $functionCalls
     */
    public function setFunctionCalls(array $functionCalls): void
    {
        $this->functionCalls = $functionCalls;
    }

    public function callsFunction(string $function): bool
    {
        foreach ($this->functionCalls as $functionCall) {
            if (strcasecmp($functionCall, $function) === 0) {
                return true;
            }
        }

        return false;
    }

    public function usesLanguageConstruct(string $construct): bool
    {
        if (in_array($construct, $this->languageConstructs, true)) {
            return true;
        }

        // `die` is a pure alias of `exit`, so banning either spelling catches both.
        return match ($construct) {
            'exit'  => in_array('die', $this->languageConstructs, true),
            'die'   => in_array('exit', $this->languageConstructs, true),
            default => false,
        };
    }

    public function accessesSuperglobals(): bool
    {
        return $this->superglobals !== [];
    }
}
