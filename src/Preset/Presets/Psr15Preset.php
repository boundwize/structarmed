<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Preset\Presets;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\PresetInterface;
use Boundwize\StructArmed\Rule\Rules\Class_\ClassImplementingInterfaceMustHaveSuffixRule;
use Boundwize\StructArmed\Rule\Rules\Class_\MustImplementInterfaceRule;

final readonly class Psr15Preset implements PresetInterface
{
    use ResolvesSourceLayerName;

    public const MIDDLEWARE_MUST_IMPLEMENT_MIDDLEWARE_INTERFACE =
        'psr15.middleware.must_implement_middleware_interface';

    public const HANDLER_MUST_IMPLEMENT_REQUEST_HANDLER_INTERFACE =
        'psr15.handler.must_implement_request_handler_interface';

    public const MIDDLEWARE_INTERFACE_IMPLEMENTATION_MUST_HAVE_MIDDLEWARE_SUFFIX =
        'psr15.middleware_interface_implementation.must_have_middleware_suffix';

    public const REQUEST_HANDLER_INTERFACE_IMPLEMENTATION_MUST_HAVE_HANDLER_SUFFIX =
        'psr15.request_handler_interface_implementation.must_have_handler_suffix';

    private const MIDDLEWARE_INTERFACE = 'Psr\\Http\\Server\\MiddlewareInterface';

    private const REQUEST_HANDLER_INTERFACE = 'Psr\\Http\\Server\\RequestHandlerInterface';

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
            self::MIDDLEWARE_MUST_IMPLEMENT_MIDDLEWARE_INTERFACE,
            new MustImplementInterfaceRule(
                layer: $layerName,
                interface: self::MIDDLEWARE_INTERFACE,
                classNamePattern: '/Middleware$/'
            )
        );

        $architecture->rule(
            self::HANDLER_MUST_IMPLEMENT_REQUEST_HANDLER_INTERFACE,
            new MustImplementInterfaceRule(
                layer: $layerName,
                interface: self::REQUEST_HANDLER_INTERFACE,
                classNamePattern: '/Handler$/'
            )
        );

        $architecture->rule(
            self::MIDDLEWARE_INTERFACE_IMPLEMENTATION_MUST_HAVE_MIDDLEWARE_SUFFIX,
            new ClassImplementingInterfaceMustHaveSuffixRule(
                layer: $layerName,
                interface: self::MIDDLEWARE_INTERFACE,
                suffix: 'Middleware'
            )
        );

        $architecture->rule(
            self::REQUEST_HANDLER_INTERFACE_IMPLEMENTATION_MUST_HAVE_HANDLER_SUFFIX,
            new ClassImplementingInterfaceMustHaveSuffixRule(
                layer: $layerName,
                interface: self::REQUEST_HANDLER_INTERFACE,
                suffix: 'Handler'
            )
        );
    }
}
