<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\JsonRecast;

use Boundwize\JsonRecast\JsonRecast;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitor;
use Boundwize\JsonRecast\Parser\ParseError;

use function file_get_contents;
use function file_put_contents;
use function is_file;

final readonly class JsonRecastFixerProcessor
{
    public function process(string $file, NodeJsonVisitor $nodeJsonVisitor): bool
    {
        if (! is_file($file)) {
            return false;
        }

        $json = (string) file_get_contents($file);

        try {
            $result = JsonRecast::traverse(
                JsonRecast::parse($json),
                $nodeJsonVisitor
            );
        } catch (ParseError) {
            return false;
        }

        $fixedJson = JsonRecast::print($result);

        return $fixedJson !== $json && file_put_contents($file, $fixedJson) !== false;
    }
}
