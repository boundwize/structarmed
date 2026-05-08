<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Preset\Presets;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\PresetInterface;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeFinalRule;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeInterfaceRule;
use Boundwize\StructArmed\Rule\Rules\Class_\NamingConventionRule;
use Boundwize\StructArmed\Rule\Rules\Layer\MayNotDependOnRule;
use Boundwize\StructArmed\Rule\Rules\Method\MaxCyclomaticComplexityRule;
use Boundwize\StructArmed\Rule\Rules\Method\MaxMethodLengthRule;
use Boundwize\StructArmed\Rule\Rules\Method\MustHaveReturnTypeRule;
use Boundwize\StructArmed\Rule\Rules\Usage\MayNotCallFunctionRule;
use Boundwize\StructArmed\Rule\Rules\Usage\MayNotUseClassRule;

final class DddPreset implements PresetInterface
{
    // -------------------------------------------------------------------------
    // Rule key constants — use these with replaceRule() and withoutRule()
    // -------------------------------------------------------------------------

    // Layer rules
    public const DOMAIN_NOT_DEPEND_APPLICATION        = 'ddd.layer.domain_not_depend_application';
    public const DOMAIN_NOT_DEPEND_INFRASTRUCTURE     = 'ddd.layer.domain_not_depend_infrastructure';
    public const APPLICATION_NOT_DEPEND_INFRASTRUCTURE = 'ddd.layer.application_not_depend_infrastructure';

    // Entity rules
    public const ENTITY_MUST_BE_FINAL                 = 'ddd.entity.must_be_final';
    public const ENTITY_MUST_HAVE_RETURN_TYPES        = 'ddd.entity.must_have_return_types';

    // Value object rules
    public const VALUE_OBJECT_MUST_BE_FINAL           = 'ddd.value_object.must_be_final';
    public const VALUE_OBJECT_NO_DATETIME             = 'ddd.value_object.no_datetime';

    // Repository rules
    public const REPOSITORY_MUST_BE_INTERFACE         = 'ddd.repository.must_be_interface';
    public const REPOSITORY_IMPL_IN_INFRASTRUCTURE    = 'ddd.repository.implementation_in_infrastructure';

    // Service rules
    public const DOMAIN_SERVICE_IN_DOMAIN             = 'ddd.service.domain_service_in_domain';
    public const APP_SERVICE_IN_APPLICATION           = 'ddd.service.app_service_in_application';

    // Event rules
    public const EVENT_MUST_BE_FINAL                  = 'ddd.event.must_be_final';
    public const EVENT_IN_DOMAIN                      = 'ddd.event.must_be_in_domain';
    public const EVENT_NO_DATETIME                    = 'ddd.event.no_datetime';

    // Safety rules
    public const DOMAIN_NO_DATETIME                   = 'ddd.safety.domain_no_datetime';
    public const DOMAIN_NO_BASE_EXCEPTION             = 'ddd.safety.domain_no_base_exception';

    public function __construct(
        private readonly int $maxComplexity = 5,
        private readonly int $maxMethodLength = 20,
        private readonly bool $enforceFinalEntities = true,
        private readonly bool $enforceFinalValueObjects = true,
        private readonly bool $enforceFinalEvents = true,
    ) {}

    public function apply(Architecture $architecture): void
    {
        $this
            ->applyLayerRules($architecture)
            ->applyEntityRules($architecture)
            ->applyValueObjectRules($architecture)
            ->applyRepositoryRules($architecture)
            ->applyServiceRules($architecture)
            ->applyEventRules($architecture)
            ->applySafetyRules($architecture);
    }

    private function applyLayerRules(Architecture $architecture): self
    {
        $architecture->rule(
            self::DOMAIN_NOT_DEPEND_APPLICATION,
            new MayNotDependOnRule(from: 'Domain', to: 'Application', toPath: 'Application')
        );

        $architecture->rule(
            self::DOMAIN_NOT_DEPEND_INFRASTRUCTURE,
            new MayNotDependOnRule(from: 'Domain', to: 'Infrastructure', toPath: 'Infrastructure')
        );

        $architecture->rule(
            self::APPLICATION_NOT_DEPEND_INFRASTRUCTURE,
            new MayNotDependOnRule(from: 'Application', to: 'Infrastructure', toPath: 'Infrastructure')
        );

        return $this;
    }

    private function applyEntityRules(Architecture $architecture): self
    {
        if ($this->enforceFinalEntities) {
            $architecture->rule(
                self::ENTITY_MUST_BE_FINAL,
                new MustBeFinalRule(layer: 'Domain', classNamePattern: '/Entity$/')
            );
        }

        $architecture->rule(
            self::ENTITY_MUST_HAVE_RETURN_TYPES,
            new MustHaveReturnTypeRule(layer: 'Domain', classNamePattern: '/Entity$/')
        );

        return $this;
    }

    private function applyValueObjectRules(Architecture $architecture): self
    {
        if ($this->enforceFinalValueObjects) {
            $architecture->rule(
                self::VALUE_OBJECT_MUST_BE_FINAL,
                new MustBeFinalRule(layer: 'Domain', classNamePattern: '/ValueObject$/')
            );
        }

        $architecture->rule(
            self::VALUE_OBJECT_NO_DATETIME,
            new MayNotUseClassRule(
                layer: 'Domain',
                classNamePattern: '/ValueObject$/',
                forbiddenClass: \DateTime::class
            )
        );

        return $this;
    }

    private function applyRepositoryRules(Architecture $architecture): self
    {
        $architecture->rule(
            self::REPOSITORY_MUST_BE_INTERFACE,
            new MustBeInterfaceRule(layer: 'Domain', classNamePattern: '/Repository$/')
        );

        $architecture->rule(
            self::REPOSITORY_IMPL_IN_INFRASTRUCTURE,
            new NamingConventionRule(
                classNamePattern: '/Repository$/',
                mustBeInLayer: 'Infrastructure',
                excludeInterfaces: true
            )
        );

        return $this;
    }

    private function applyServiceRules(Architecture $architecture): self
    {
        $architecture->rule(
            self::DOMAIN_SERVICE_IN_DOMAIN,
            new NamingConventionRule(
                classNamePattern: '/DomainService$/',
                mustBeInLayer: 'Domain'
            )
        );

        $architecture->rule(
            self::APP_SERVICE_IN_APPLICATION,
            new NamingConventionRule(
                classNamePattern: '/Service$/',
                mustBeInLayer: 'Application',
                excludePattern: '/DomainService$/'
            )
        );

        return $this;
    }

    private function applyEventRules(Architecture $architecture): self
    {
        if ($this->enforceFinalEvents) {
            $architecture->rule(
                self::EVENT_MUST_BE_FINAL,
                new MustBeFinalRule(layer: 'Domain', classNamePattern: '/Event$/')
            );
        }

        $architecture->rule(
            self::EVENT_IN_DOMAIN,
            new NamingConventionRule(
                classNamePattern: '/Event$/',
                mustBeInLayer: 'Domain'
            )
        );

        $architecture->rule(
            self::EVENT_NO_DATETIME,
            new MayNotUseClassRule(
                layer: 'Domain',
                classNamePattern: '/Event$/',
                forbiddenClass: \DateTime::class
            )
        );

        return $this;
    }

    private function applySafetyRules(Architecture $architecture): self
    {
        $architecture->rule(
            self::DOMAIN_NO_DATETIME,
            new MayNotUseClassRule(layer: 'Domain', forbiddenClass: \DateTime::class)
        );

        $architecture->rule(
            self::DOMAIN_NO_BASE_EXCEPTION,
            new MayNotUseClassRule(layer: 'Domain', forbiddenClass: \Exception::class)
        );

        foreach (['Domain', 'Application'] as $layer) {
            $architecture->rule(
                sprintf('ddd.safety.%s_max_complexity', strtolower($layer)),
                new MaxCyclomaticComplexityRule(layer: $layer, maxComplexity: $this->maxComplexity)
            );

            $architecture->rule(
                sprintf('ddd.safety.%s_max_method_length', strtolower($layer)),
                new MaxMethodLengthRule(layer: $layer, maxLines: $this->maxMethodLength)
            );

            foreach (['dd', 'dump', 'var_dump', 'print_r', 'die', 'exit'] as $fn) {
                $architecture->rule(
                    sprintf('ddd.safety.%s_no_%s', strtolower($layer), $fn),
                    new MayNotCallFunctionRule(layer: $layer, function: $fn)
                );
            }
        }

        return $this;
    }
}
