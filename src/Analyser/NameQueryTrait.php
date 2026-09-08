<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use function str_ends_with;
use function str_starts_with;
use function strtolower;

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
        $lowerSuffix    = strtolower($suffix);
        $lowerShortName = strtolower($this->shortName());

        return str_ends_with($lowerShortName, $lowerSuffix);
    }

    public function nameStartsWith(string $prefix): bool
    {
        $lowerPrefix    = strtolower($prefix);
        $lowerShortName = strtolower($this->shortName());

        return str_starts_with($lowerShortName, $lowerPrefix);
    }
}
