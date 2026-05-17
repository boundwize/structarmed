<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Visitor;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\NodeVisitorAbstract;

use function in_array;
use function is_string;

final class SuperglobalCollectorVisitor extends NodeVisitorAbstract
{
    /** @var string[] */
    public array $found = [];

    /** @param string[] $superglobals */
    public function __construct(private readonly array $superglobals)
    {
    }

    public function enterNode(Node $node): null
    {
        if (
            $node instanceof Variable
            && is_string($node->name)
            && in_array($node->name, $this->superglobals, true)
        ) {
            $this->found[] = '$' . $node->name;
        }

        return null;
    }
}
