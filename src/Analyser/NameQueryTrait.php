<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use function str_ends_with;
use function str_starts_with;

/**
 * Short-name query helpers shared by {@see ClassNode} and {@see FunctionNode}.
 *
 * @internal
 */
trait NameQueryTrait
{
    abstract public function shortName(): string;

    public function nameEndsWith(string $suffix): bool
    {
        return str_ends_with($this->shortName(), $suffix);
    }

    public function nameStartsWith(string $prefix): bool
    {
        return str_starts_with($this->shortName(), $prefix);
    }
}
