<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

/**
 * An anonymous class declaration (`new class ... {}`). Anonymous classes never
 * become ClassNodes — they cannot be referenced by name and no rule targets
 * them directly — but the class they extend is still extended within the
 * scanned paths, which extended-class-aware rules must take into account.
 *
 * The usage example is on MustBeFinalRule, which must skip if target class is extended by an anonymous class.
 *
 * Note: Other properties like anonymous class's traits, implements, etc may come
 * later if needed for future needed rules.
 */
final readonly class AnonymousClassNode
{
    public function __construct(
        public string $file,
        public int $line,
        public ?string $extends,
    ) {
    }
}
