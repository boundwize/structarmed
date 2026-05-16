<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\File;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\MultipleProjectRuleViolationInterface;
use Boundwize\StructArmed\Rule\RuleViolation;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Else_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\InlineHTML;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Nop;
use PhpParser\Node\Stmt\Use_;
use PhpParser\ParserFactory;

use function file_get_contents;
use function sprintf;
use function trim;

final readonly class Psr1SymbolsOrSideEffectsRule implements MultipleProjectRuleViolationInterface
{
    /**
     * @param list<string>|null $sourcePaths
     */
    public function __construct(
        private ?array $sourcePaths = null,
        private ?PhpFileFinder $phpFileFinder = null,
    ) {
    }

    public function evaluateProject(string $basePath, Architecture $architecture, array $skipPaths = []): ?RuleViolation
    {
        return $this->evaluateProjectAll($basePath, $architecture, $skipPaths)[0] ?? null;
    }

    /**
     * @param list<string> $skipPaths
     * @return RuleViolation[]
     */
    public function evaluateProjectAll(string $basePath, Architecture $architecture, array $skipPaths = []): array
    {
        $parser        = (new ParserFactory())->createForNewestSupportedVersion();
        $phpFileFinder = $this->phpFileFinder ?? new PhpFileFinder($this->sourcePaths);
        $violations    = [];

        foreach ($phpFileFinder->files($basePath, $skipPaths) as $file) {
            try {
                $nodes = $parser->parse((string) file_get_contents($file)) ?? [];
            } catch (Error) {
                continue;
            }

            $fileState = $this->fileState($nodes);

            if ($fileState['declaresSymbols'] && $fileState['hasSideEffects']) {
                $violations[] = new RuleViolation(
                    message: sprintf(
                        'File [%s] should either declare symbols or cause side effects, not both',
                        $file
                    ),
                    file: $file,
                    line: $fileState['sideEffectLine'],
                    className: '',
                );
            }
        }

        return $violations;
    }

    /**
     * @param array<Node\Stmt> $nodes
     * @return array{declaresSymbols: bool, hasSideEffects: bool, sideEffectLine: int}
     */
    private function fileState(array $nodes): array
    {
        $declaresSymbols = false;
        $hasSideEffects  = false;
        $sideEffectLine  = 1;

        foreach ($nodes as $node) {
            if ($node instanceof Namespace_) {
                $state           = $this->fileState($node->stmts);
                $declaresSymbols = $declaresSymbols || $state['declaresSymbols'];
                $hasSideEffects  = $hasSideEffects || $state['hasSideEffects'];
                $sideEffectLine  = $state['hasSideEffects'] ? $state['sideEffectLine'] : $sideEffectLine;

                continue;
            }

            if ($this->isSymbolDeclaration($node)) {
                $declaresSymbols = true;
                continue;
            }

            if ($this->isNeutralStatement($node)) {
                continue;
            }

            if ($node instanceof If_ && $this->containsOnlyDeclarations($node)) {
                $declaresSymbols = true;
                continue;
            }

            $hasSideEffects = true;
            $sideEffectLine = $node->getStartLine();
        }

        return [
            'declaresSymbols' => $declaresSymbols,
            'hasSideEffects'  => $hasSideEffects,
            'sideEffectLine'  => $sideEffectLine,
        ];
    }

    private function isSymbolDeclaration(Stmt $stmt): bool
    {
        return $stmt instanceof ClassLike
            || $stmt instanceof Function_;
    }

    private function isNeutralStatement(Stmt $stmt): bool
    {
        return $stmt instanceof Declare_
            || $stmt instanceof Use_
            || $stmt instanceof GroupUse
            || $stmt instanceof Nop
            || ($stmt instanceof InlineHTML && trim($stmt->value) === '');
    }

    private function containsOnlyDeclarations(If_ $if): bool
    {
        if ($if->elseifs !== [] || $if->else instanceof Else_) {
            return false;
        }

        foreach ($if->stmts as $statement) {
            if (! $this->isSymbolDeclaration($statement) && ! $this->isNeutralStatement($statement)) {
                return false;
            }
        }

        return true;
    }
}
