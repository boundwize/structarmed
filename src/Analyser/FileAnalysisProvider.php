<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use Boundwize\StructArmed\Util\InlineHtmlOpeningTagMatcher;
use Boundwize\StructArmed\Util\Path;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\Eval_;
use PhpParser\Node\Expr\Exit_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\Print_;
use PhpParser\Node\Expr\ShellExec;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Const_;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Else_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\InlineHTML;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Nop;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\Token;

use function array_fill_keys;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_values;
use function file_get_contents;
use function min;
use function preg_match;
use function str_starts_with;
use function substr;
use function substr_count;
use function trim;

use const T_INLINE_HTML;
use const T_OPEN_TAG;

final class FileAnalysisProvider
{
    private readonly Parser $parser;

    /** @var array<string, array<Node\Stmt>|null> */
    private array $asts = [];

    /** @var array<string, bool> */
    private array $validAsts = [];

    /** @var array<string, array<Token>> */
    private array $tokens = [];

    /** @var array<string, string> */
    private array $contents = [];

    /** @var array<string, int|null> */
    private array $invalidPhpTagLines = [];

    /** @var array<string, true> */
    private readonly array $scopeFileMap;

    /**
     * @param array<string, FileAnalysis> $analyses
     * @param bool $isScopeFilesEnabled False for standalone rule evaluation, which discovers configured paths.
     */
    public function __construct(
        private array $analyses = [],
        private readonly bool $isScopeFilesEnabled = false,
    ) {
        $normalisedAnalyses = [];
        foreach ($this->analyses as $file => $analysis) {
            $normalisedAnalyses[Path::normalise($file, canonicalise: true)] = $analysis;
        }

        $this->analyses     = $normalisedAnalyses;
        $this->scopeFileMap = array_fill_keys(array_keys($normalisedAnalyses), true);
        $this->parser       = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * @param list<string> $files
     * @return list<string>
     */
    public function filesInScope(array $files): array
    {
        if (! $this->isScopeFilesEnabled) {
            return $files;
        }

        return array_values(array_filter(
            $files,
            fn(string $file): bool => isset($this->scopeFileMap[Path::normalise($file, canonicalise: true)]),
        ));
    }

    public function analyse(string $file): FileAnalysis
    {
        $file = Path::normalise($file, canonicalise: true);

        if (isset($this->analyses[$file])) {
            return $this->analyses[$file];
        }

        $code        = $this->contents($file);
        $ast         = $this->ast($file);
        $hasValidAst = $this->validAsts[$file];
        $fileState   = $hasValidAst ? $this->fileState($ast ?? []) : [
            'declaresSymbols' => false,
            'hasSideEffects'  => false,
            'sideEffectLine'  => 1,
        ];

        $fileAnalysis = new FileAnalysis(
            file: $file,
            hasUtf8Bom: str_starts_with($code, "\xEF\xBB\xBF"),
            hasValidUtf8: preg_match('//u', $code) === 1,
            invalidPhpTagLine: $this->invalidPhpTagLine($file),
            hasValidAst: $hasValidAst,
            declaresSymbols: $fileState['declaresSymbols'],
            hasSideEffects: $fileState['hasSideEffects'],
            sideEffectLine: $fileState['sideEffectLine'],
        );

        $this->analyses[$file] = $fileAnalysis;
        unset($this->contents[$file], $this->invalidPhpTagLines[$file]);

        return $fileAnalysis;
    }

    /** @return array<Node\Stmt>|null */
    public function ast(string $file, bool $retainForAnalysis = true): ?array
    {
        if (! $retainForAnalysis) {
            try {
                return $this->parser->parse((string) file_get_contents($file));
            } catch (Error) {
                return null;
            }
        }

        $file = Path::normalise($file, canonicalise: true);

        if (array_key_exists($file, $this->asts)) {
            return $this->asts[$file];
        }

        if (isset($this->analyses[$file])) {
            return null;
        }

        $ast     = null;
        $isValid = true;

        try {
            $ast = $this->parser->parse($this->contents($file));
        } catch (Error) {
            $isValid = false;
        }

        $this->asts[$file]      = $ast;
        $this->validAsts[$file] = $isValid;
        $this->tokens[$file]    = $this->parser->getTokens();

        return $ast;
    }

    public function releaseAst(string $file): void
    {
        $file = Path::normalise($file, canonicalise: true);

        unset(
            $this->asts[$file],
            $this->validAsts[$file],
            $this->tokens[$file],
            $this->contents[$file],
        );
    }

    public function hasUtf8Bom(string $file): bool
    {
        $file = Path::normalise($file, canonicalise: true);

        return $this->analyses[$file]->hasUtf8Bom ?? str_starts_with($this->contents($file), "\xEF\xBB\xBF");
    }

    public function hasValidUtf8(string $file): bool
    {
        $file = Path::normalise($file, canonicalise: true);

        return $this->analyses[$file]->hasValidUtf8 ?? preg_match('//u', $this->contents($file)) === 1;
    }

    public function invalidPhpTagLine(string $file): ?int
    {
        $file = Path::normalise($file, canonicalise: true);

        if (isset($this->analyses[$file])) {
            return $this->analyses[$file]->invalidPhpTagLine;
        }

        if (array_key_exists($file, $this->invalidPhpTagLines)) {
            return $this->invalidPhpTagLines[$file];
        }

        if (! isset($this->tokens[$file])) {
            $this->ast($file);
        }

        return $this->invalidPhpTagLines[$file] = $this->invalidPhpTagLineFromTokens($this->tokens[$file] ?? []);
    }

    /** @param array<Token> $tokens */
    private function invalidPhpTagLineFromTokens(array $tokens): ?int
    {
        foreach ($tokens as $token) {
            $invalidLine = $this->invalidPhpTagLineForToken($token->id, $token->text, $token->line);

            if ($invalidLine !== null) {
                return $invalidLine;
            }
        }

        return null;
    }

    private function invalidPhpTagLineForToken(int $id, string $text, int $tokenLine): ?int
    {
        if ($id === T_OPEN_TAG) {
            return preg_match('/^<\?php(?:\s|$)/', $text) === 1 ? null : $tokenLine;
        }

        if (
            $id !== T_INLINE_HTML
            || ($tagOffset = InlineHtmlOpeningTagMatcher::invalidInlineHtmlTagOffset($text)) === null
        ) {
            return null;
        }

        return $tokenLine + substr_count(substr($text, 0, $tagOffset), "\n");
    }

    private function contents(string $file): string
    {
        $file = Path::normalise($file, canonicalise: true);

        return $this->contents[$file] ??= (string) file_get_contents($file);
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
            if ($declaresSymbols && $hasSideEffects) {
                break;
            }

            if (($node instanceof Namespace_ || $node instanceof Declare_) && $node->stmts !== null) {
                $state           = $this->fileState($node->stmts);
                $declaresSymbols = $declaresSymbols || $state['declaresSymbols'];

                if (! $hasSideEffects && $state['hasSideEffects']) {
                    $sideEffectLine = $state['sideEffectLine'];
                }

                $hasSideEffects = $hasSideEffects || $state['hasSideEffects'];

                continue;
            }

            if ($this->isSymbolDeclaration($node)) {
                $declaresSymbols = true;
                continue;
            }

            if ($this->isNeutralStatement($node)) {
                continue;
            }

            if ($node instanceof If_) {
                $conditions  = [$node->cond];
                $branchStmts = $node->stmts;
                foreach ($node->elseifs as $elseif) {
                    $conditions[] = $elseif->cond;
                    $branchStmts  = array_merge($branchStmts, $elseif->stmts);
                }

                if ($node->else instanceof Else_) {
                    $branchStmts = array_merge($branchStmts, $node->else->stmts);
                }

                $state           = $this->fileState($branchStmts);
                $declaresSymbols = $declaresSymbols || $state['declaresSymbols'];

                $branchLines   = $state['hasSideEffects'] ? [$state['sideEffectLine']] : [];
                $conditionLine = $this->conditionSideEffectLine($conditions);
                if ($conditionLine !== null) {
                    $branchLines[] = $conditionLine;
                }

                if ($branchLines !== []) {
                    if (! $hasSideEffects) {
                        $sideEffectLine = min($branchLines);
                    }

                    $hasSideEffects = true;
                }

                continue;
            }

            if (! $hasSideEffects) {
                $sideEffectLine = $node->getStartLine();
            }

            $hasSideEffects = true;
        }

        return [
            'declaresSymbols' => $declaresSymbols,
            'hasSideEffects'  => $hasSideEffects,
            'sideEffectLine'  => $sideEffectLine,
        ];
    }

    /**
     * PSR-1 allows conditional symbol declarations, but the condition itself is still executed:
     * `if (include 'bootstrap.php')` performs an include, which PSR-1 names as a side effect.
     * Only unambiguous effects are reported, so declaration guards such as `function_exists()`
     * or `PHP_VERSION_ID >= 80400` stay neutral. Bodies of closures and anonymous classes are
     * skipped, as declaring them executes nothing.
     *
     * @param list<Expr> $conditions
     */
    private function conditionSideEffectLine(array $conditions): ?int
    {
        $nodeVisitor = new class extends NodeVisitorAbstract {
            public ?int $sideEffectLine = null;

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof FunctionLike || $node instanceof ClassLike) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                if (! $this->isSideEffectExpression($node)) {
                    return null;
                }

                $this->sideEffectLine = $node->getStartLine();

                return NodeVisitor::STOP_TRAVERSAL;
            }

            private function isSideEffectExpression(Node $node): bool
            {
                return $node instanceof Include_
                    || $node instanceof Eval_
                    || $node instanceof Exit_
                    || $node instanceof Print_
                    || $node instanceof ShellExec
                    || $node instanceof Throw_
                    || $node instanceof Assign
                    || $node instanceof AssignRef
                    || $node instanceof AssignOp
                    || $node instanceof PreInc
                    || $node instanceof PreDec
                    || $node instanceof PostInc
                    || $node instanceof PostDec;
            }
        };

        (new NodeTraverser($nodeVisitor))->traverse($conditions);

        return $nodeVisitor->sideEffectLine;
    }

    private function isSymbolDeclaration(Stmt $stmt): bool
    {
        return $stmt instanceof ClassLike
            || $stmt instanceof Function_
            || $stmt instanceof Const_
            || $this->isDefineCall($stmt)
            || $this->isConditionalDefineStatement($stmt);
    }

    /**
     * A top-level `define('CONST', ...)` call declares a constant symbol under PSR-1,
     * mirroring PHP_CodeSniffer's PSR1.Files.SideEffects sniff. Method calls such as
     * `$obj->define(...)` or `Foo::define(...)` are not FuncCall nodes, so they never match.
     */
    private function isDefineCall(Stmt $stmt): bool
    {
        return $stmt instanceof Expression
            && $stmt->expr instanceof FuncCall
            && $stmt->expr->name instanceof Name
            && $stmt->expr->name->toLowerString() === 'define';
    }

    /** `defined('X') || define('X', ...)` and `!defined('X') && define('X', ...)` patterns */
    private function isConditionalDefineStatement(Stmt $stmt): bool
    {
        if (! $stmt instanceof Expression) {
            return false;
        }

        $expr = $stmt->expr;

        if (
            ! ($expr instanceof BooleanOr || $expr instanceof LogicalOr
            || $expr instanceof BooleanAnd || $expr instanceof LogicalAnd)
        ) {
            return false;
        }

        return ($this->isDefineFuncCall($expr->right) && $this->isDefinedCondition($expr->left))
            || ($this->isDefineFuncCall($expr->left) && $this->isDefinedCondition($expr->right));
    }

    private function isDefineFuncCall(Expr $expr): bool
    {
        return $expr instanceof FuncCall
            && $expr->name instanceof Name
            && $expr->name->toLowerString() === 'define';
    }

    private function isDefinedCondition(Expr $expr): bool
    {
        if (
            $expr instanceof FuncCall
            && $expr->name instanceof Name
            && $expr->name->toLowerString() === 'defined'
        ) {
            return true;
        }

        if ($expr instanceof BooleanNot) {
            return $this->isDefinedCondition($expr->expr);
        }

        if (
            $expr instanceof BooleanAnd || $expr instanceof LogicalAnd
            || $expr instanceof BooleanOr || $expr instanceof LogicalOr
        ) {
            return $this->isDefinedCondition($expr->left) && $this->isDefinedCondition($expr->right);
        }

        return false;
    }

    private function isNeutralStatement(Stmt $stmt): bool
    {
        return $stmt instanceof Declare_
            || $stmt instanceof Use_
            || $stmt instanceof GroupUse
            || $stmt instanceof Nop
            || ($stmt instanceof InlineHTML && trim($stmt->value) === '');
    }
}
