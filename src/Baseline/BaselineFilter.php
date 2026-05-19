<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Baseline;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\RuleViolationCollection;

final readonly class BaselineFilter
{
    public function apply(
        RuleViolationCollection $ruleViolationCollection,
        Architecture $architecture,
        string $basePath
    ): RuleViolationCollection {
        if ($architecture->getBaseline() === null) {
            return $ruleViolationCollection;
        }

        return (new Baseline())->filter(
            $ruleViolationCollection,
            $architecture->getBaseline(),
            $basePath
        );
    }
}
