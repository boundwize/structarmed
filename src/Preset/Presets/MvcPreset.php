<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Preset\Presets;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\PresetInterface;
use Boundwize\StructArmed\Rule\Rules\Class_\ClassNameMustHaveSuffixRule;
use Boundwize\StructArmed\Rule\Rules\Class_\ClassNameMustNotHavePrefixRule;
use Boundwize\StructArmed\Rule\Rules\Class_\MaxDependencyCountRule;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeFinalRule;
use Boundwize\StructArmed\Rule\Rules\Layer\MayNotDependOnRule;
use Boundwize\StructArmed\Rule\Rules\Method\MaxCyclomaticComplexityRule;
use Boundwize\StructArmed\Rule\Rules\Method\MaxMethodLengthRule;
use Boundwize\StructArmed\Rule\Rules\Method\MustHaveReturnTypeRule;
use Boundwize\StructArmed\Rule\Rules\Usage\MayNotCallFunctionRule;
use Boundwize\StructArmed\Rule\Rules\Usage\MayNotUseClassRule;
use Boundwize\StructArmed\Rule\Rules\Usage\MayNotUseSuperglobalsRule;
use DateTime;
use PDO;

use function sprintf;
use function strtolower;

final readonly class MvcPreset implements PresetInterface
{
    // -------------------------------------------------------------------------
    // Rule key constants — use these with skipRule() and replaceRule()
    // -------------------------------------------------------------------------

    // Layer rules
    public const CONTROLLER_NOT_DEPEND_VIEW = 'mvc.layer.controller_not_depend_view';

    public const MODEL_NOT_DEPEND_CONTROLLER = 'mvc.layer.model_not_depend_controller';

    public const MODEL_NOT_DEPEND_VIEW = 'mvc.layer.model_not_depend_view';

    public const VIEW_NOT_DEPEND_CONTROLLER = 'mvc.layer.view_not_depend_controller';

    public const VIEW_NOT_DEPEND_MODEL = 'mvc.layer.view_not_depend_model';

    // Controller rules
    public const CONTROLLER_NAME_MUST_END_WITH_CONTROLLER = 'mvc.controller.name_must_end_with_controller';

    public const CONTROLLER_MUST_BE_FINAL = 'mvc.controller.must_be_final';

    public const CONTROLLER_MAX_COMPLEXITY = 'mvc.controller.max_complexity';

    public const CONTROLLER_MAX_METHOD_LENGTH = 'mvc.controller.max_method_length';

    public const CONTROLLER_MAX_DEPENDENCIES = 'mvc.controller.max_dependencies';

    public const CONTROLLER_NO_PDO = 'mvc.controller.no_pdo';

    public const CONTROLLER_NO_SUPERGLOBALS = 'mvc.controller.no_superglobals';

    public const CONTROLLER_MUST_HAVE_RETURN_TYPES = 'mvc.controller.must_have_return_types';

    // Model rules
    public const MODEL_NAME_MUST_NOT_START_WITH_MODEL = 'mvc.model.name_must_not_start_with_model';

    public const MODEL_NO_ECHO = 'mvc.model.no_echo';

    public const MODEL_NO_PRINT = 'mvc.model.no_print';

    public const MODEL_NO_HEADER = 'mvc.model.no_header';

    public const MODEL_NO_SUPERGLOBALS = 'mvc.model.no_superglobals';

    public const MODEL_NO_DATETIME = 'mvc.model.no_datetime';

    public const MODEL_MUST_HAVE_RETURN_TYPES = 'mvc.model.must_have_return_types';

    // View rules
    public const VIEW_MAX_COMPLEXITY = 'mvc.view.max_complexity';

    public const VIEW_NO_PDO = 'mvc.view.no_pdo';

    public const VIEW_NO_HEADER = 'mvc.view.no_header';

    public const VIEW_NO_SUPERGLOBALS = 'mvc.view.no_superglobals';

    // Service rules
    public const SERVICE_MUST_BE_FINAL = 'mvc.service.must_be_final';

    public const SERVICE_NO_ECHO = 'mvc.service.no_echo';

    public const SERVICE_NO_HEADER = 'mvc.service.no_header';

    public const SERVICE_NO_SUPERGLOBALS = 'mvc.service.no_superglobals';

    public const SERVICE_MUST_HAVE_RETURN_TYPES = 'mvc.service.must_have_return_types';

    public function __construct(
        private int $controllerMaxComplexity = 5,
        private int $controllerMaxMethodLength = 20,
        private int $controllerMaxDependencies = 5,
        private int $viewMaxComplexity = 3,
    ) {
    }

    public function apply(Architecture $architecture): void
    {
        $this
            ->applyLayerRules($architecture)
            ->applyControllerRules($architecture)
            ->applyModelRules($architecture)
            ->applyViewRules($architecture)
            ->applyServiceRules($architecture)
            ->applySafetyRules($architecture);
    }

    private function applyLayerRules(Architecture $architecture): self
    {
        $architecture->rule(
            self::CONTROLLER_NOT_DEPEND_VIEW,
            new MayNotDependOnRule(from: 'Controller', to: 'View', toPath: 'View')
        );

        $architecture->rule(
            self::MODEL_NOT_DEPEND_CONTROLLER,
            new MayNotDependOnRule(from: 'Model', to: 'Controller', toPath: 'Controller')
        );

        $architecture->rule(
            self::MODEL_NOT_DEPEND_VIEW,
            new MayNotDependOnRule(from: 'Model', to: 'View', toPath: 'View')
        );

        $architecture->rule(
            self::VIEW_NOT_DEPEND_CONTROLLER,
            new MayNotDependOnRule(from: 'View', to: 'Controller', toPath: 'Controller')
        );

        $architecture->rule(
            self::VIEW_NOT_DEPEND_MODEL,
            new MayNotDependOnRule(from: 'View', to: 'Model', toPath: 'Model')
        );

        return $this;
    }

    private function applyControllerRules(Architecture $architecture): self
    {
        $architecture->rule(
            self::CONTROLLER_NAME_MUST_END_WITH_CONTROLLER,
            new ClassNameMustHaveSuffixRule(layer: 'Controller', suffix: 'Controller')
        );

        $architecture->rule(
            self::CONTROLLER_MUST_BE_FINAL,
            new MustBeFinalRule(layer: 'Controller', classNamePattern: '/Controller$/')
        );

        $architecture->rule(
            self::CONTROLLER_MAX_COMPLEXITY,
            new MaxCyclomaticComplexityRule(
                layer: 'Controller',
                maxComplexity: $this->controllerMaxComplexity
            )
        );

        $architecture->rule(
            self::CONTROLLER_MAX_METHOD_LENGTH,
            new MaxMethodLengthRule(
                layer: 'Controller',
                maxLines: $this->controllerMaxMethodLength
            )
        );

        $architecture->rule(
            self::CONTROLLER_MAX_DEPENDENCIES,
            new MaxDependencyCountRule(
                layer: 'Controller',
                maxCount: $this->controllerMaxDependencies
            )
        );

        $architecture->rule(
            self::CONTROLLER_NO_PDO,
            new MayNotUseClassRule(layer: 'Controller', forbiddenClass: PDO::class)
        );

        $architecture->rule(
            self::CONTROLLER_NO_SUPERGLOBALS,
            new MayNotUseSuperglobalsRule(layer: 'Controller')
        );

        $architecture->rule(
            self::CONTROLLER_MUST_HAVE_RETURN_TYPES,
            new MustHaveReturnTypeRule(layer: 'Controller')
        );

        return $this;
    }

    private function applyModelRules(Architecture $architecture): self
    {
        $architecture->rule(
            self::MODEL_NAME_MUST_NOT_START_WITH_MODEL,
            new ClassNameMustNotHavePrefixRule(layer: 'Model', prefix: 'Model')
        );

        $architecture->rule(
            self::MODEL_NO_ECHO,
            new MayNotCallFunctionRule(layer: 'Model', function: 'echo')
        );

        $architecture->rule(
            self::MODEL_NO_PRINT,
            new MayNotCallFunctionRule(layer: 'Model', function: 'print')
        );

        $architecture->rule(
            self::MODEL_NO_HEADER,
            new MayNotCallFunctionRule(layer: 'Model', function: 'header')
        );

        $architecture->rule(
            self::MODEL_NO_SUPERGLOBALS,
            new MayNotUseSuperglobalsRule(layer: 'Model')
        );

        $architecture->rule(
            self::MODEL_NO_DATETIME,
            new MayNotUseClassRule(layer: 'Model', forbiddenClass: DateTime::class)
        );

        $architecture->rule(
            self::MODEL_MUST_HAVE_RETURN_TYPES,
            new MustHaveReturnTypeRule(layer: 'Model')
        );

        return $this;
    }

    private function applyViewRules(Architecture $architecture): self
    {
        $architecture->rule(
            self::VIEW_MAX_COMPLEXITY,
            new MaxCyclomaticComplexityRule(
                layer: 'View',
                maxComplexity: $this->viewMaxComplexity
            )
        );

        $architecture->rule(
            self::VIEW_NO_PDO,
            new MayNotUseClassRule(layer: 'View', forbiddenClass: PDO::class)
        );

        $architecture->rule(
            self::VIEW_NO_HEADER,
            new MayNotCallFunctionRule(layer: 'View', function: 'header')
        );

        $architecture->rule(
            self::VIEW_NO_SUPERGLOBALS,
            new MayNotUseSuperglobalsRule(layer: 'View')
        );

        return $this;
    }

    private function applyServiceRules(Architecture $architecture): self
    {
        $architecture->rule(
            self::SERVICE_MUST_BE_FINAL,
            new MustBeFinalRule(layer: 'Service', classNamePattern: '/Service$/')
        );

        $architecture->rule(
            self::SERVICE_NO_ECHO,
            new MayNotCallFunctionRule(layer: 'Service', function: 'echo')
        );

        $architecture->rule(
            self::SERVICE_NO_HEADER,
            new MayNotCallFunctionRule(layer: 'Service', function: 'header')
        );

        $architecture->rule(
            self::SERVICE_NO_SUPERGLOBALS,
            new MayNotUseSuperglobalsRule(layer: 'Service')
        );

        $architecture->rule(
            self::SERVICE_MUST_HAVE_RETURN_TYPES,
            new MustHaveReturnTypeRule(layer: 'Service')
        );

        return $this;
    }

    private function applySafetyRules(Architecture $architecture): self
    {
        foreach (['Controller', 'Model', 'View', 'Service'] as $layer) {
            foreach (['dd', 'dump', 'var_dump', 'print_r', 'var_export', 'die', 'exit'] as $fn) {
                $architecture->rule(
                    sprintf('mvc.safety.%s_no_%s', strtolower($layer), $fn),
                    new MayNotCallFunctionRule(layer: $layer, function: $fn)
                );
            }
        }

        return $this;
    }
}
