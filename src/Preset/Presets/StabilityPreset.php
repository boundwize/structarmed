<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Preset\Presets;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\PresetInterface;
use Boundwize\StructArmed\Rule\Rules\Class_\ApiMayNotBeInternalRule;
use Boundwize\StructArmed\Rule\Rules\Class_\ApiMayNotExposeInternalRule;
use Boundwize\StructArmed\Rule\Rules\Class_\ApiMayNotExtendInternalRule;

/**
 * Stability preset: keeps the stable `@api` surface apart from `@internal`
 * code — a class-like is not tagged both, an `@api` class or interface does
 * not extend an `@internal` one, which would expose its members, and an `@api`
 * class-like does not name an `@internal` type in its signature.
 *
 * Trade-off: only docblock tags of scanned class-likes are known. Class-likes
 * without either tag are not checked.
 */
final readonly class StabilityPreset implements PresetInterface
{
    use ResolvesSourceLayerNameTrait;

    public const API_MAY_NOT_BE_INTERNAL = 'stability.api.may_not_be_internal';

    public const API_MAY_NOT_EXTEND_INTERNAL = 'stability.api.may_not_extend_internal';

    public const API_MAY_NOT_EXPOSE_INTERNAL = 'stability.api.may_not_expose_internal';

    /**
     * @param list<string>|null $sourcePaths
     */
    public function __construct(
        private ?array $sourcePaths = null,
    ) {
    }

    public function apply(Architecture $architecture): void
    {
        $layerName = $this->resolveLayerName($architecture);
        $architecture->layer($layerName, $this->sourcePaths ?? []);

        $architecture->rule(
            self::API_MAY_NOT_BE_INTERNAL,
            new ApiMayNotBeInternalRule($layerName)
        );
        $architecture->rule(
            self::API_MAY_NOT_EXTEND_INTERNAL,
            new ApiMayNotExtendInternalRule($layerName)
        );
        $architecture->rule(
            self::API_MAY_NOT_EXPOSE_INTERNAL,
            new ApiMayNotExposeInternalRule($layerName)
        );
    }
}
