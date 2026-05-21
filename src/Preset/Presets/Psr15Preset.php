<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Preset\Presets;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\PresetInterface;
use Boundwize\StructArmed\Rule\Rules\Class_\MustImplementInterfaceRule;

final readonly class Psr15Preset implements PresetInterface
{
    public const SOURCE_LAYER = 'Source';

    public const MIDDLEWARE_MUST_IMPLEMENT_MIDDLEWARE_INTERFACE =
        'psr15.middleware.must_implement_middleware_interface';

    public const HANDLER_MUST_IMPLEMENT_REQUEST_HANDLER_INTERFACE =
        'psr15.handler.must_implement_request_handler_interface';

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
        $architecture->layer(self::SOURCE_LAYER, $this->sourcePaths ?? []);
        $architecture->rule(
            self::MIDDLEWARE_MUST_IMPLEMENT_MIDDLEWARE_INTERFACE,
            new MustImplementInterfaceRule(
                layer: self::SOURCE_LAYER,
                interface: self::MIDDLEWARE_INTERFACE,
                classNamePattern: '/Middleware$/'
            )
        );

        $architecture->rule(
            self::HANDLER_MUST_IMPLEMENT_REQUEST_HANDLER_INTERFACE,
            new MustImplementInterfaceRule(
                layer: self::SOURCE_LAYER,
                interface: self::REQUEST_HANDLER_INTERFACE,
                classNamePattern: '/Handler$/'
            )
        );
    }
}
