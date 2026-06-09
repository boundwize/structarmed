<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use Boundwize\StructArmed\LayerResolver\LayerResolverInterface;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\Empty_;
use PhpParser\Node\Expr\Eval_;
use PhpParser\Node\Expr\Exit_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Expr\Isset_;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\Print_;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Case_;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\Echo_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeVisitorAbstract;

use function array_merge;
use function array_pop;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function is_string;
use function spl_object_id;
use function strtolower;

final class ClassCollector extends NodeVisitorAbstract
{
    private const SUPERGLOBALS = [
        '_GET',
        '_POST',
        '_REQUEST',
        '_SESSION',
        '_COOKIE',
        '_SERVER',
        '_ENV',
        '_FILES',
        'GLOBALS',
    ];

    /** @var list<ClassNode> */
    private array $nodes = [];

    private string $currentFile = '';

    /** @var string[] */
    private array $fileUses = [];

    /** @var ClassLike[] */
    private array $fileClassLikes = [];

    /** @var string[] */
    private array $fileFunctions = [];

    /**
     * @var array<int, array{
     *     dependencies: list<string>,
     *     functionCallNames: list<Name>,
     *     superglobals: string[],
     *     languageConstructs: string[],
     *     complexityByMethodId: array<int, int>
     * }>
     */
    private array $classLikeAnalysis = [];

    /** @var list<int> */
    private array $activeClassLikeIds = [];

    /** @var list<int> */
    private array $activeMethodIds = [];

    /** @var array<int, int> */
    private array $methodClassLikeIds = [];

    public function __construct(
        private readonly LayerResolverInterface $layerResolver
    ) {
    }

    public function setCurrentFile(string $file): void
    {
        $this->currentFile        = $file;
        $this->fileUses           = [];
        $this->fileClassLikes     = [];
        $this->fileFunctions      = [];
        $this->classLikeAnalysis  = [];
        $this->activeClassLikeIds = [];
        $this->activeMethodIds    = [];
        $this->methodClassLikeIds = [];
    }

    /** @return list<ClassNode> */
    public function getNodes(): array
    {
        return $this->nodes;
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Use_) {
            foreach ($node->uses as $use) {
                $this->fileUses[] = $use->name->toString();
            }
        }

        if ($node instanceof GroupUse) {
            $prefix = $node->prefix->toString();

            foreach ($node->uses as $use) {
                $this->fileUses[] = $prefix . '\\' . $use->name->toString();
            }
        }

        if ($node instanceof Function_ && isset($node->namespacedName)) {
            $this->fileFunctions[] = $node->namespacedName->toString();
        }

        if ($node instanceof ClassLike && $node->name instanceof Identifier) {
            $this->startClassLikeAnalysis($node);
        }

        if ($node instanceof ClassMethod) {
            $this->startMethodAnalysis($node);
        }

        $this->collectNodeAnalysis($node);

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof ClassMethod) {
            $this->finishMethodAnalysis($node);
        }

        if (! $node instanceof ClassLike) {
            return null;
        }

        if (! $node->name instanceof Identifier) {
            return null;
        }

        $this->fileClassLikes[] = $node;
        array_pop($this->activeClassLikeIds);

        return null;
    }

    /** @param Node[] $nodes */
    public function afterTraverse(array $nodes): null
    {
        foreach ($this->fileClassLikes as $fileClassLike) {
            $this->collectClassLike($fileClassLike);
        }

        $this->fileClassLikes     = [];
        $this->classLikeAnalysis  = [];
        $this->activeClassLikeIds = [];
        $this->activeMethodIds    = [];
        $this->methodClassLikeIds = [];

        return null;
    }

    private function startClassLikeAnalysis(ClassLike $classLike): void
    {
        $classLikeId = spl_object_id($classLike);

        $this->classLikeAnalysis[$classLikeId] = [
            'dependencies'         => [],
            'functionCallNames'    => [],
            'superglobals'         => [],
            'languageConstructs'   => [],
            'complexityByMethodId' => [],
        ];
        $this->activeClassLikeIds[]            = $classLikeId;

        foreach ($classLike->getMethods() as $classMethod) {
            $this->methodClassLikeIds[spl_object_id($classMethod)] = $classLikeId;
        }
    }

    private function startMethodAnalysis(ClassMethod $classMethod): void
    {
        $methodId = spl_object_id($classMethod);

        if (! isset($this->methodClassLikeIds[$methodId])) {
            return;
        }

        $classLikeId = $this->methodClassLikeIds[$methodId];

        $this->activeMethodIds[] = $methodId;

        $this->classLikeAnalysis[$classLikeId]['complexityByMethodId'][$methodId] = 1;
    }

    private function finishMethodAnalysis(ClassMethod $classMethod): void
    {
        if (! isset($this->methodClassLikeIds[spl_object_id($classMethod)])) {
            return;
        }

        array_pop($this->activeMethodIds);
    }

    private function collectNodeAnalysis(Node $node): void
    {
        if ($this->activeClassLikeIds === []) {
            return;
        }

        if ($node instanceof FullyQualified) {
            $name = $node->toString();
            if (! in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                $this->addDependency($name);
            }
        }

        if (
            $node instanceof FuncCall
            && $node->name instanceof Name
        ) {
            $this->addFunctionCallName($node->name);
        }

        if ($node instanceof Exit_) {
            $this->addLanguageConstruct(
                $node->getAttribute('kind') === Exit_::KIND_DIE
                    ? 'die'
                    : 'exit'
            );
        }

        if ($node instanceof Echo_) {
            $this->addLanguageConstruct('echo');
        }

        if ($node instanceof Print_) {
            $this->addLanguageConstruct('print');
        }

        if ($node instanceof Include_) {
            $this->addLanguageConstruct(match ($node->type) {
                Include_::TYPE_REQUIRE      => 'require',
                Include_::TYPE_INCLUDE_ONCE => 'include_once',
                Include_::TYPE_REQUIRE_ONCE => 'require_once',
                default                     => 'include',
            });
        }

        if ($node instanceof Isset_) {
            $this->addLanguageConstruct('isset');
        }

        if ($node instanceof Empty_) {
            $this->addLanguageConstruct('empty');
        }

        if ($node instanceof Unset_) {
            $this->addLanguageConstruct('unset');
        }

        if ($node instanceof Eval_) {
            $this->addLanguageConstruct('eval');
        }

        if ($node instanceof List_) {
            $this->addLanguageConstruct('list');
        }

        if (
            $node instanceof Variable
            && is_string($node->name)
            && in_array($node->name, self::SUPERGLOBALS, true)
        ) {
            $this->addSuperglobal('$' . $node->name);
        }

        if ($this->activeMethodIds !== [] && $this->isComplexityBranch($node)) {
            foreach ($this->activeMethodIds as $activeMethodId) {
                $classLikeId = $this->methodClassLikeIds[$activeMethodId];

                $this->classLikeAnalysis[$classLikeId]['complexityByMethodId'][$activeMethodId]++;
            }
        }
    }

    private function addDependency(string $dependency): void
    {
        foreach ($this->activeClassLikeIds as $classLikeId) {
            $this->classLikeAnalysis[$classLikeId]['dependencies'][] = $dependency;
        }
    }

    private function addFunctionCallName(Name $functionCallName): void
    {
        foreach ($this->activeClassLikeIds as $classLikeId) {
            $this->classLikeAnalysis[$classLikeId]['functionCallNames'][] = $functionCallName;
        }
    }

    private function addSuperglobal(string $superglobal): void
    {
        foreach ($this->activeClassLikeIds as $classLikeId) {
            $this->classLikeAnalysis[$classLikeId]['superglobals'][] = $superglobal;
        }
    }

    private function addLanguageConstruct(string $languageConstruct): void
    {
        foreach ($this->activeClassLikeIds as $classLikeId) {
            $this->classLikeAnalysis[$classLikeId]['languageConstructs'][] = $languageConstruct;
        }
    }

    private function collectClassLike(ClassLike $classLike): void
    {
        $analysis   = $this->collectClassLikeAnalysis($classLike);
        $className  = $this->resolveClassName($classLike);
        $layers     = $this->layerResolver->resolveAll($className, $this->currentFile);
        $layer      = $this->layerResolver->resolve($className, $this->currentFile);
        $methods    = $this->collectMethods($classLike, $analysis['complexityByMethodId']);
        $implements = $this->collectImplements($classLike);
        $traits     = $this->collectTraits($classLike);
        $constants  = $this->collectConstants($classLike);
        $properties = $this->collectProperties($classLike);

        $this->nodes[] = new ClassNode(
            className:          $className,
            file:               $this->currentFile,
            line:               $classLike->getStartLine(),
            layer:              $layer,
            extends:            $classLike instanceof Class_ && $classLike->extends instanceof Name
                                    ? $classLike->extends->toString()
                                    : null,
            isAbstract:         $classLike instanceof Class_ && $classLike->isAbstract(),
            isFinal:            $classLike instanceof Class_ && $classLike->isFinal(),
            isInterface:        $classLike instanceof Interface_,
            isReadonly:         $classLike instanceof Class_ && $classLike->isReadonly(),
            isTrait:            $classLike instanceof Trait_,
            dependencies:       $analysis['dependencies'],
            implements:         $implements,
            traits:             $traits,
            methods:            $methods,
            constants:          $constants,
            properties:         $properties,
            functionCalls:      $analysis['functionCalls'],
            superglobals:       $analysis['superglobals'],
            languageConstructs: $analysis['languageConstructs'],
            layers:             $layers,
            isEnum:             $classLike instanceof Enum_,
        );
    }

    /**
     * @return ConstantNode[]
     */
    private function collectConstants(ClassLike $classLike): array
    {
        $constants = [];

        foreach ($classLike->stmts as $stmt) {
            if (! $stmt instanceof ClassConst) {
                continue;
            }

            $visibility            = $this->resolveVisibilityFromFlags($stmt->flags);
            $hasExplicitVisibility = $this->hasExplicitVisibilityFlag($stmt->flags);

            foreach ($stmt->consts as $const) {
                $constants[] = new ConstantNode(
                    name:                 (string) $const->name,
                    visibility:           $visibility,
                    hasExplicitVisibility: $hasExplicitVisibility,
                    line:                 $const->getStartLine(),
                );
            }
        }

        return $constants;
    }

    /**
     * @return PropertyNode[]
     */
    private function collectProperties(ClassLike $classLike): array
    {
        $properties = [];

        foreach ($classLike->stmts as $stmt) {
            if (! $stmt instanceof Property) {
                continue;
            }

            $visibility            = $this->resolveVisibilityFromFlags($stmt->flags);
            $hasExplicitVisibility = $this->hasExplicitVisibilityFlag($stmt->flags);

            foreach ($stmt->props as $prop) {
                $properties[] = new PropertyNode(
                    name:                 (string) $prop->name,
                    visibility:           $visibility,
                    hasExplicitVisibility: $hasExplicitVisibility,
                    line:                 $prop->getStartLine(),
                );
            }
        }

        foreach ($classLike->getMethods() as $classMethod) {
            if ($classMethod->name->toLowerString() !== '__construct') {
                continue;
            }

            foreach ($classMethod->params as $param) {
                if ($param->flags === 0 || ! $param->var instanceof Variable || ! is_string($param->var->name)) {
                    continue;
                }

                $properties[] = new PropertyNode(
                    name:                  (string) $param->var->name,
                    visibility:            $this->resolveVisibilityFromFlags($param->flags),
                    hasExplicitVisibility: $this->hasExplicitVisibilityFlag($param->flags),
                    line:                  $param->getStartLine(),
                );
            }

            // stop since __construct() already processed
            break;
        }

        return $properties;
    }

    private function resolveClassName(ClassLike $classLike): string
    {
        return isset($classLike->namespacedName)
            ? $classLike->namespacedName->toString()
            : (string) $classLike->name;
    }

    /**
     * @return array{
     *     dependencies: list<string>,
     *     functionCalls: string[],
     *     superglobals: string[],
     *     languageConstructs: string[],
     *     complexityByMethodId: array<int, int>
     * }
     */
    private function collectClassLikeAnalysis(ClassLike $classLike): array
    {
        $analysis      = $this->classLikeAnalysis[spl_object_id($classLike)] ?? [
            'dependencies'         => [],
            'functionCallNames'    => [],
            'superglobals'         => [],
            'languageConstructs'   => [],
            'complexityByMethodId' => [],
        ];
        $functionCalls = [];

        foreach ($analysis['functionCallNames'] as $functionCallName) {
            $functionCalls[] = $this->resolveFunctionName($functionCallName);
        }

        return [
            'dependencies'         => array_values(array_unique(array_merge(
                $this->fileUses,
                $analysis['dependencies']
            ))),
            'functionCalls'        => array_values(array_unique($functionCalls)),
            'superglobals'         => array_values(array_unique($analysis['superglobals'])),
            'languageConstructs'   => array_values(array_unique($analysis['languageConstructs'])),
            'complexityByMethodId' => $analysis['complexityByMethodId'],
        ];
    }

    private function resolveFunctionName(Name $name): string
    {
        $functionName = $name->toString();

        if ($name instanceof FullyQualified) {
            return $functionName;
        }

        $namespacedName = $name->getAttribute('namespacedName');

        if (
            $namespacedName instanceof Name
            && in_array($namespacedName->toString(), $this->fileFunctions, true)
        ) {
            return $namespacedName->toString();
        }

        return $functionName;
    }

    private function isComplexityBranch(Node $node): bool
    {
        return $node instanceof If_
            || $node instanceof ElseIf_
            || $node instanceof For_
            || $node instanceof Foreach_
            || $node instanceof While_
            || $node instanceof Do_
            || $node instanceof Case_
            || $node instanceof Catch_
            || $node instanceof Ternary
            || $node instanceof BooleanAnd
            || $node instanceof BooleanOr
            || $node instanceof LogicalAnd
            || $node instanceof LogicalOr
            || $node instanceof Match_;
    }

    /**
     * @return string[]
     */
    private function collectImplements(ClassLike $classLike): array
    {
        $interfaces = [];

        if ($classLike instanceof Class_ || $classLike instanceof Enum_) {
            foreach ($classLike->implements as $interface) {
                $interfaces[] = $interface->toString();
            }
        }

        return $interfaces;
    }

    /**
     * @return string[]
     */
    private function collectTraits(ClassLike $classLike): array
    {
        if ($classLike instanceof Interface_) {
            return [];
        }

        $traits = [];

        foreach ($classLike->stmts as $stmt) {
            if (! $stmt instanceof TraitUse) {
                continue;
            }

            foreach ($stmt->traits as $trait) {
                $traits[] = $trait->toString();
            }
        }

        return $traits;
    }

    /**
     * @return MethodNode[]
     * @param array<int, int> $complexityByMethodId
     */
    private function collectMethods(ClassLike $classLike, array $complexityByMethodId): array
    {
        $methods = [];

        foreach ($classLike->getMethods() as $classMethod) {
            $methods[] = new MethodNode(
                name:                 (string) $classMethod->name,
                visibility:           $this->resolveVisibilityFromFlags($classMethod->flags),
                hasReturnType:        $classMethod->returnType !== null,
                isStatic:             $classMethod->isStatic(),
                paramCount:           count($classMethod->params),
                cyclomaticComplexity: $complexityByMethodId[spl_object_id($classMethod)] ?? 1,
                lineCount:            $this->calculateMethodLineCount($classMethod),
                hasExplicitVisibility: $this->hasExplicitVisibilityFlag($classMethod->flags),
                line:                 $classMethod->getStartLine(),
            );
        }

        return $methods;
    }

    private function resolveVisibilityFromFlags(int $flags): string
    {
        if (($flags & Modifiers::PROTECTED) !== 0) {
            return 'protected';
        }

        if (($flags & Modifiers::PRIVATE) !== 0) {
            return 'private';
        }

        return 'public';
    }

    private function hasExplicitVisibilityFlag(int $flags): bool
    {
        return ($flags & Modifiers::VISIBILITY_MASK) !== 0;
    }

    private function calculateMethodLineCount(ClassMethod $classMethod): int
    {
        if ($classMethod->stmts === null || $classMethod->stmts === []) {
            return 0;
        }

        $lastIndex = count($classMethod->stmts) - 1;
        return $classMethod->stmts[$lastIndex]->getEndLine() - $classMethod->stmts[0]->getStartLine() + 1;
    }
}
