<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

/**
 * An anonymous class declaration (`new class ... {}`). Anonymous classes never
 * become ClassNodes — they cannot be referenced by name and no rule targets
 * them directly — but the class they extend, the interfaces they implement,
 * and the traits they use are still used within the scanned paths, which
 * usage-aware rules must take into account.
 *
 * The usage example is on MustBeFinalRule, which must skip if target class is extended by an anonymous class.
 */
final readonly class AnonymousClassNode
{
    /**
     * @param string[] $implements Interface names this anonymous class implements
     * @param string[] $traits     Trait names this anonymous class uses
     */
    public function __construct(
        public string $file,
        public int $line,
        public ?string $extends,
        public array $implements = [],
        public array $traits = [],
    ) {
    }
}
