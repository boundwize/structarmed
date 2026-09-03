<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use function in_array;

/**
 * Layer membership shared by every node the analyser produces. Composed into
 * {@see NodeQueryTrait} for the nodes with a body, and used directly by
 * {@see AnonymousClassNode}, which carries no body-level facts of its own.
 *
 * @internal
 *
 * @property list<string> $layers All layer names this node belongs to; assigned once in each node's constructor
 */
trait LayerQueryTrait
{
    public function isInLayer(string $layer): bool
    {
        return in_array($layer, $this->layers, true);
    }
}
