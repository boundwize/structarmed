<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Capture\CollectorState;

/**
 * Name and imports of the namespace currently being traversed. Replaced on
 * entering each namespace block and when starting a new file.
 *
 * @internal
 */
final class NamespaceAnalysis
{
    /** @var array<string, true> */
    public array $uses = [];

    /** @var array<string, true> Class imports only, excluding use function and use const */
    public array $classUses = [];

    public function __construct(
        /** The value of __NAMESPACE__; empty in the global namespace. */
        public readonly string $name = '',
    ) {
    }
}
