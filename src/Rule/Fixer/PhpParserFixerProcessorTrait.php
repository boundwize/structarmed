<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer;

trait PhpParserFixerProcessorTrait
{
    private static function fixerProcessor(): PhpParserFixerProcessor
    {
        static $fixerProcessor;
        return $fixerProcessor ??= new PhpParserFixerProcessor();
    }
}
