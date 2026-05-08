<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Preset\Presets;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\PresetInterface;
use Boundwize\StructArmed\Rule\Rules\Class_\MaxDependencyCountRule;
use Boundwize\StructArmed\Rule\Rules\Method\MaxCyclomaticComplexityRule;
use Boundwize\StructArmed\Rule\Rules\Method\MaxMethodLengthRule;
use Boundwize\StructArmed\Rule\Rules\Method\MustHaveReturnTypeRule;
use Boundwize\StructArmed\Rule\Rules\Usage\MayNotCallFunctionRule;

use function sprintf;
use function strtolower;

final class Psr4Preset implements PresetInterface
{
    private const SOURCE_LAYER = 'Source';

    public const SOURCE_MUST_HAVE_RETURN_TYPES = 'psr4.source.must_have_return_types';
    public const SOURCE_MAX_COMPLEXITY         = 'psr4.source.max_complexity';
    public const SOURCE_MAX_METHOD_LENGTH      = 'psr4.source.max_method_length';
    public const SOURCE_MAX_DEPENDENCIES       = 'psr4.source.max_dependencies';

    /**
     * @param list<string> $sourcePaths
     */
    public function __construct(
        private readonly array $sourcePaths = ['src/'],
        private readonly int $maxComplexity = 5,
        private readonly int $maxMethodLength = 20,
        private readonly int $maxDependencies = 5,
    ) {
    }

    public function apply(Architecture $architecture): void
    {
        $architecture->layer(self::SOURCE_LAYER, $this->sourcePaths);

        $architecture->rule(
            self::SOURCE_MUST_HAVE_RETURN_TYPES,
            new MustHaveReturnTypeRule(layer: self::SOURCE_LAYER)
        );

        $architecture->rule(
            self::SOURCE_MAX_COMPLEXITY,
            new MaxCyclomaticComplexityRule(
                layer:         self::SOURCE_LAYER,
                maxComplexity: $this->maxComplexity
            )
        );

        $architecture->rule(
            self::SOURCE_MAX_METHOD_LENGTH,
            new MaxMethodLengthRule(
                layer:    self::SOURCE_LAYER,
                maxLines: $this->maxMethodLength
            )
        );

        $architecture->rule(
            self::SOURCE_MAX_DEPENDENCIES,
            new MaxDependencyCountRule(
                layer:    self::SOURCE_LAYER,
                maxCount: $this->maxDependencies
            )
        );

        foreach (['dd', 'dump', 'var_dump', 'print_r', 'var_export', 'die', 'exit'] as $fn) {
            $architecture->rule(
                sprintf('psr4.safety.%s_no_%s', strtolower(self::SOURCE_LAYER), $fn),
                new MayNotCallFunctionRule(layer: self::SOURCE_LAYER, function: $fn)
            );
        }
    }
}
