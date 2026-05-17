<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Visitor;

use PhpParser\Node;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\NodeVisitorAbstract;

use function implode;
use function in_array;
use function strtolower;

final class DependencyCollectorVisitor extends NodeVisitorAbstract
{
    /** @var string[] */
    public array $names = [];

    public function enterNode(Node $node): null
    {
        if ($node instanceof FullyQualified) {
            $name = implode('\\', $node->getParts());
            if (! in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                $this->names[] = $name;
            }
        }

        return null;
    }
}
