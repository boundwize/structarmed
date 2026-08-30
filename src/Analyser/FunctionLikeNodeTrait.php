<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use function in_array;
use function rtrim;
use function str_starts_with;
use function strcasecmp;

/**
 * Query helpers shared by {@see FunctionNode} and {@see AnonymousFunctionNode}.
 * Both nodes carry the same body-level facts as a ClassNode — dependencies,
 * function calls, superglobals, language constructs — so rules can ask the
 * same questions of a function body that they ask of a class-like.
 *
 * @internal
 */
trait FunctionLikeNodeTrait
{
    public function isInLayer(string $layer): bool
    {
        return in_array($layer, $this->layers, true);
    }

    public function dependsOn(string $class): bool
    {
        return in_array($class, $this->dependencies, true);
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
