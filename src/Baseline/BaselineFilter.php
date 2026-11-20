<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Baseline;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\RuleViolationCollection;
use Boundwize\StructArmed\Util\Path;

use function file_exists;
use function sprintf;

final readonly class BaselineFilter
{
    public function apply(
        RuleViolationCollection $ruleViolationCollection,
        Architecture $architecture,
        string $basePath
    ): RuleViolationCollection {
        $baseline = $architecture->getBaseline();

        // A configured baseline that is not there yet must not block the
        // analysis: violations are reported unfiltered, next to a warning.
        if ($baseline === null || $this->missingWarning($architecture, $basePath) !== null) {
            return $ruleViolationCollection;
        }

        return (new Baseline())->filter($ruleViolationCollection, $baseline, $basePath);
    }

    /**
     * The warning to show when the configured baseline file does not exist,
     * null when no baseline is configured or its file is there.
     */
    public function missingWarning(Architecture $architecture, string $basePath): ?string
    {
        $baseline = $architecture->getBaseline();

        if ($baseline === null || file_exists(Path::resolve($baseline, $basePath))) {
            return null;
        }

        return sprintf(
            'Baseline file [%s] does not exist, violations are reported without baseline filtering.',
            $baseline
        );
    }
}
