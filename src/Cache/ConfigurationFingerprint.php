<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cache;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\AbstractLayerAwareRule;
use Boundwize\StructArmed\Rule\AnonymousClassRuleInterface;
use Boundwize\StructArmed\Rule\AnonymousFunctionRuleInterface;
use Boundwize\StructArmed\Rule\FunctionRuleInterface;
use Boundwize\StructArmed\Rule\ProjectRuleInterface;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4NamespaceRule;
use Closure;
use DateTimeImmutable;
use ReflectionClass;
use ReflectionFunction;
use SplObjectStorage;
use UnitEnum;

use function get_resource_type;
use function gettype;
use function hash;
use function in_array;
use function is_array;
use function is_callable;
use function is_object;
use function is_resource;
use function ksort;
use function serialize;

/**
 * Captures evaluated configuration without invoking user code or serializing service graphs.
 *
 * @internal
 */
final class ConfigurationFingerprint
{
    private const MAX_DEPTH = 32;

    /** Native state populated by analysis, rather than configuration loading. */
    private const RUNTIME_PROPERTIES = [
        AbstractLayerAwareRule::class => ['classNodeMap'],
        Psr4NamespaceRule::class      => ['projectBasePath', 'mappingsByBasePath', 'basePathByDirectory'],
    ];

    /** @var SplObjectStorage<object, array<string, string>> */
    private SplObjectStorage $objects;

    private int $boundaries = 0;

    public function __construct()
    {
        $this->objects = new SplObjectStorage();
    }

    public function hash(Architecture $architecture): string
    {
        $this->objects    = new SplObjectStorage();
        $this->boundaries = 0;
        $rules            = [];

        foreach ($architecture->getRules() as $key => $rule) {
            if (! in_array($key, $architecture->getSkippedRuleKeys(), true)) {
                $rules[$key] = $this->properties($rule, [], 0);
            }
        }

        // Registration order affects precedence. Baselines are applied after cached
        // evaluation; the cache directory is a storage location, not an evaluation input.
        $fingerprint = hash('xxh128', serialize([
            $architecture->getLayers(),
            $architecture->getLayerExcludePaths(),
            $architecture->getLayerPatterns(),
            $architecture->getRuleset(),
            $architecture->getClassViolationSkips(),
            $architecture->getRulesetSkipPaths(),
            $architecture->getSkipPaths(),
            $architecture->getRuleSkipPaths(),
            $architecture->getSkippedRuleKeys(),
            $rules,
        ]));

        // SplObjectStorage holds strong references; release the graph after this run.
        $this->objects = new SplObjectStorage();

        return $fingerprint;
    }

    /**
     * Rules contribute configured state, excluding known injected runtime fields.
     * Other objects contribute only readonly properties, leaving mutable service state opaque.
     *
     * @param list<object> $ancestors
     * @return list<mixed>
     */
    private function properties(object $object, array $ancestors, int $depth, bool $readonlyOnly = false): array
    {
        $class    = new ReflectionClass($object);
        $identity = $this->classIdentity($class);

        $isLazy = $this->reflectionMethod($class, 'isUninitializedLazyObject');
        if ($isLazy instanceof Closure && $isLazy($object)) {
            return ['opaque-lazy', $identity];
        }

        if (in_array($object, $ancestors, true) || $depth >= self::MAX_DEPTH) {
            $this->boundaries++;

            return ['boundary', $identity];
        }

        $memoKey = $depth . ':' . ($readonlyOnly ? 'readonly' : 'all');

        if (isset($this->objects[$object][$memoKey])) {
            return ['object-digest', $this->objects[$object][$memoKey]];
        }

        $boundaries  = $this->boundaries;
        $ancestors[] = $object;
        $properties  = [];

        do {
            foreach ($class->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $class->getName() || $property->isStatic()) {
                    continue;
                }

                if ($readonlyOnly && ! $property->isReadOnly()) {
                    continue;
                }

                $name = $property->getName();

                if (in_array($name, self::RUNTIME_PROPERTIES[$class->getName()] ?? [], true)) {
                    continue;
                }

                // PHP 8.4 hooks may run arbitrary code. Virtual properties have no
                // stored configuration; backed properties are read without hooks.
                $isVirtual = $this->reflectionMethod($property, 'isVirtual');
                if ($isVirtual instanceof Closure && $isVirtual()) {
                    continue;
                }

                $value = ['uninitialized'];

                if ($property->isInitialized($object)) {
                    $getRawValue = $this->reflectionMethod($property, 'getRawValue');
                    $stored      = $getRawValue instanceof Closure
                        ? $getRawValue($object)
                        : $property->getValue($object);
                    $value       = $this->value($stored, $ancestors, $depth + 1);
                }

                $properties[] = [$this->classIdentity($class), $name, $value];
            }

            $class = $class->getParentClass();
        } while ($class !== false);

        $digest = hash('xxh128', serialize(['object', $identity, $properties]));

        // Cycles and depth cutoffs depend on the current traversal path. Never
        // memoize them. Depth and property selection belong to the memo key, not the persisted identity.
        if ($this->boundaries === $boundaries) {
            $memo                   = $this->objects[$object] ?? [];
            $memo[$memoKey]         = $digest;
            $this->objects[$object] = $memo;
        }

        return ['object-digest', $digest];
    }

    /**
     * @param list<object> $ancestors
     */
    private function value(mixed $value, array $ancestors, int $depth): mixed
    {
        if ($depth >= self::MAX_DEPTH) {
            $this->boundaries++;

            return ['boundary'];
        }

        if (is_array($value)) {
            $items = [];

            foreach ($value as $key => $item) {
                $items[] = [$key, $this->value($item, $ancestors, $depth + 1)];
            }

            return ['array', $items];
        }

        if ($value instanceof UnitEnum) {
            return ['enum', $value::class, $value->name];
        }

        if ($value instanceof DateTimeImmutable) {
            return ['date', $value::class, DateTimeImmutable::createFromInterface($value)->format('Y-m-d H:i:s.u e')];
        }

        if ($value instanceof Closure) {
            $function = new ReflectionFunction($value);
            $captures = $function->getStaticVariables();
            ksort($captures);
            $scope  = $function->getClosureScopeClass();
            $called = $function->getClosureCalledClass();

            // Reflection exposes no source column or anonymous bytecode identity.
            // Distinct same-line bodies with identical context remain indistinguishable.
            return [
                'closure',
                $function->isAnonymous() ? null : $function->getName(),
                $scope instanceof ReflectionClass ? $this->classIdentity($scope) : null,
                $called instanceof ReflectionClass ? $this->classIdentity($called) : null,
                $this->value($function->getClosureThis(), $ancestors, $depth + 1),
                $function->getFileName(),
                $function->getStartLine(),
                $function->getEndLine(),
                $this->value($captures, $ancestors, $depth + 1),
            ];
        }

        if (is_object($value)) {
            $isRule = $value instanceof RuleInterface
                || $value instanceof ProjectRuleInterface
                || $value instanceof FunctionRuleInterface
                || $value instanceof AnonymousFunctionRuleInterface
                || $value instanceof AnonymousClassRuleInterface;

            return $this->properties($value, $ancestors, $depth, readonlyOnly: ! $isRule);
        }

        if (is_resource($value)) {
            return ['resource', get_resource_type($value)];
        }

        return gettype($value) === 'resource (closed)' ? ['resource', 'closed'] : ['scalar', $value];
    }

    /** Resolve optional reflection APIs without requiring PHP 8.4 method declarations. */
    private function reflectionMethod(object $reflection, string $method): ?Closure
    {
        return is_callable([$reflection, $method]) ? Closure::fromCallable([$reflection, $method]) : null;
    }

    /**
     * Anonymous class names contain a generated counter, so use their declaration location.
     *
     * @template TObject of object
     * @param ReflectionClass<TObject> $class
     * @return string|array{string|false, int|false, int|false}
     */
    private function classIdentity(ReflectionClass $class): string|array
    {
        return $class->isAnonymous()
            ? [$class->getFileName(), $class->getStartLine(), $class->getEndLine()]
            : $class->getName();
    }
}
