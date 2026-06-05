<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Layer;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\LayerAwareRuleInterface;
use Boundwize\StructArmed\Rule\MultipleRuleViolationInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function in_array;
use function is_array;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;

final class MayNotDependOnRule implements MultipleRuleViolationInterface, LayerAwareRuleInterface
{
    private readonly string $normalisedToPath;

    /** @var array<string, list<string>>|null */
    private ?array $classLayerMap = null;

    public function __construct(
        private readonly string $from,
        private readonly string $to,
        ?string $toPath = null,
    ) {
        $this->normalisedToPath = str_replace('\\', '/', $toPath ?? $to);
    }

    /** @param array<string, string|list<string>> $classLayerMap */
    public function injectClassLayerMap(array $classLayerMap): void
    {
        $this->classLayerMap = [];

        foreach ($classLayerMap as $className => $layers) {
            $this->classLayerMap[$className] = is_array($layers) ? $layers : [$layers];
        }
    }

    public function appliesTo(ClassNode $classNode): bool
    {
        return $classNode->isInLayer($this->from);
    }

    public function evaluate(ClassNode $classNode): ?RuleViolation
    {
        $violations = $this->evaluateAll($classNode);

        return $violations[0] ?? null;
    }

    /**
     * @return RuleViolation[]
     */
    public function evaluateAll(ClassNode $classNode): array
    {
        $violations = [];

        foreach ($classNode->dependencies as $dependency) {
            if (! $this->isInForbiddenLayer($dependency)) {
                continue;
            }

            $violations[] = new RuleViolation(
                message:   sprintf(
                    'Class [%s] in layer [%s] must not depend on [%s] which belongs to layer [%s]',
                    $classNode->className,
                    $this->from,
                    $dependency,
                    $this->to
                ),
                file:      $classNode->file,
                line:      $classNode->line,
                className: $classNode->className,
                layer:     $classNode->layer,
            );
        }

        return $violations;
    }

    private function isInForbiddenLayer(string $dependency): bool
    {
        // Priority 1: Use class layer map if available
        if ($this->classLayerMap !== null) {
            $depLayers = $this->classLayerMap[$dependency] ?? null;

            if ($depLayers !== null) {
                return in_array($this->to, $depLayers, true);
            }
        }

        // Priority 2: Fallback to path matching
        $depPath = str_replace('\\', '/', $dependency);

        return str_contains($depPath . '/', '/' . $this->normalisedToPath . '/')
            || str_starts_with($depPath . '/', $this->normalisedToPath . '/');
    }
}
