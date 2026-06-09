<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use PhpParser\Node\Name;

/**
 * @internal
 */
final class ClassLikeAnalysis
{
    /** @var list<string> */
    public array $dependencies = [];

    /** @var list<Name> */
    public array $functionCallNames = [];

    /** @var string[] */
    public array $superglobals = [];

    /** @var string[] */
    public array $languageConstructs = [];

    /** @var array<int, int> */
    public array $complexityByMethodId = [];
}
