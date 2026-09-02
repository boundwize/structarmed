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
use PhpParser\Parser;
use PhpParser\ParserFactory;

use function array_key_exists;
use function array_keys;
use function file_get_contents;
use function is_array;
use function min;
use function preg_match;
use function str_contains;
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

    /** @var array<string, string> */
    private array $contents = [];

    /** @var array<string, int|null> Computed eagerly at parse time so token arrays are not retained by the provider. */
    private array $invalidPhpTagLines = [];

    /** @var list<string> */
    private readonly array $scopeFiles;

    /**
     * Creates a provider for analyses collected from an explicit analyser scope.
     *
     * @param array<string, FileAnalysis> $analyses
     * @param list<string> $scopeFiles
     */
    public static function forScope(array $analyses, array $scopeFiles): self
    {
        $normalisedAnalyses = self::normaliseAnalyses($analyses);
        $scopedAnalyses     = [];

        foreach ($scopeFiles as $scopeFile) {
            $scopeFile = Path::normalise($scopeFile, canonicalise: true);

            if (isset($normalisedAnalyses[$scopeFile])) {
                $scopedAnalyses[$scopeFile] = $normalisedAnalyses[$scopeFile];
            }
        }

        return new self($scopedAnalyses);
    }

    /** @param array<string, FileAnalysis> $analyses */
    public function __construct(private array $analyses = [])
    {
        $this->analyses   = self::normaliseAnalyses($this->analyses);
        $this->scopeFiles = array_keys($this->analyses);
        $this->parser     = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /** @return list<string> The files represented by the analyses supplied at construction. */
    public function scopeFiles(): array
    {
        return $this->scopeFiles;
    }

    /**
     * @param array<string, FileAnalysis> $analyses
     * @return array<string, FileAnalysis>
     */
    private static function normaliseAnalyses(array $analyses): array
    {
        $normalisedAnalyses = [];

        foreach ($analyses as $file => $analysis) {
            $normalisedAnalyses[Path::normalise($file, canonicalise: true)] = $analysis;
        }

        return $normalisedAnalyses;
    }

    /**
     * @param list<array{int, string}> $nonCanonicalKeywordConstants Keyword constant spellings the
     *                                                               analysis-node traversal recorded
     *                                                               for the file; the provider never
     *                                                               walks the AST for them itself.
     * @param list<array{int, string, int|float}> $numericLiterals Numeric literals recorded by the
     *                                                               same analysis-node traversal.
     */
    public function analyse(
        string $file,
        array $nonCanonicalKeywordConstants = [],
        array $numericLiterals = [],
    ): FileAnalysis {
        $file = Path::normalise($file, canonicalise: true);

        if (isset($this->analyses[$file])) {
            return $this->analyses[$file];
        }

        $code        = $this->contents($file);
        $ast         = array_key_exists($file, $this->asts) ? $this->asts[$file] : $this->parse($file);
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
            invalidPhpTagLine: $this->invalidPhpTagLines[$file],
            hasValidAst: $hasValidAst,
            declaresSymbols: $fileState['declaresSymbols'],
            hasSideEffects: $fileState['hasSideEffects'],
            sideEffectLine: $fileState['sideEffectLine'],
            nonCanonicalKeywordConstants: $nonCanonicalKeywordConstants,
            numericLiterals: $numericLiterals,
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

        return $this->parse($file);
    }

    /**
     * Parses an already normalised file that has neither a cached AST nor an
     * analysis, recording its AST, validity and invalid PHP tag line in one pass.
     *
     * @return array<Node\Stmt>|null
     */
    private function parse(string $file): ?array
    {
        $code    = $this->contents($file);
        $ast     = null;
        $isValid = true;

        try {
            $ast = $this->parser->parse($code);
        } catch (Error) {
            $isValid = false;
        }

        $this->asts[$file]               = $ast;
        $this->validAsts[$file]          = $isValid;
        $this->invalidPhpTagLines[$file] = $this->invalidPhpTagLineForCode($code);

        return $ast;
    }

    public function releaseAst(string $file): void
    {
        $file = Path::normalise($file, canonicalise: true);

        unset(
            $this->asts[$file],
            $this->validAsts[$file],
            $this->contents[$file],
            $this->invalidPhpTagLines[$file],
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

        if (! array_key_exists($file, $this->invalidPhpTagLines)) {
            $this->parse($file);
        }

        return $this->invalidPhpTagLines[$file];
    }

    /**
     * Must be called right after parsing $code, while the parser still holds its tokens.
     * A file that opens with a well-formed `<?php` tag and contains no other `<?`
     * cannot hold an invalid tag, which skips the token walk for the common case.
     */
    private function invalidPhpTagLineForCode(string $code): ?int
    {
        if (
            str_starts_with($code, '<?php')
            && ($code === '<?php' || str_contains(" \t\n\r\v\f", $code[5]))
            && substr_count($code, '<?') === 1
        ) {
            return null;
        }

        foreach ($this->parser->getTokens() as $token) {
            $id = $token->id;

            if ($id !== T_OPEN_TAG && $id !== T_INLINE_HTML) {
                continue;
            }

            $invalidLine = $this->invalidPhpTagLineForToken($id, $token->text, $token->line);

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

    /** @param string $file An already normalised path. */
    private function contents(string $file): string
    {
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

                // A define() argument can itself be effectful, e.g. define('X', include 'config.php').
                if ($node instanceof Expression) {
                    $argumentSideEffectLine = $this->intrinsicSideEffectLineInExpression($node->expr);

                    if ($argumentSideEffectLine !== null) {
                        if (! $hasSideEffects) {
                            $sideEffectLine = $argumentSideEffectLine;
                        }

                        $hasSideEffects = true;
                    }
                }

                continue;
            }

            if ($this->isNeutralStatement($node)) {
                continue;
            }

            if ($node instanceof If_) {
                $conditionSideEffectLine = $this->intrinsicSideEffectLineInExpression($node->cond);

                if ($declaresSymbols && $conditionSideEffectLine !== null) {
                    $hasSideEffects = true;
                    $sideEffectLine = $conditionSideEffectLine;

                    break;
                }

                $branchStmts = $node->stmts;
                foreach ($node->elseifs as $elseif) {
                    $elseifSideEffectLine = $this->intrinsicSideEffectLineInExpression($elseif->cond);
                    if ($elseifSideEffectLine !== null) {
                        $conditionSideEffectLine = $conditionSideEffectLine === null
                            ? $elseifSideEffectLine
                            : min($conditionSideEffectLine, $elseifSideEffectLine);
                    }

                    foreach ($elseif->stmts as $stmt) {
                        $branchStmts[] = $stmt;
                    }
                }

                if ($node->else instanceof Else_) {
                    foreach ($node->else->stmts as $stmt) {
                        $branchStmts[] = $stmt;
                    }
                }

                $state           = $this->fileState($branchStmts);
                $declaresSymbols = $declaresSymbols || $state['declaresSymbols'];
                if ($conditionSideEffectLine !== null) {
                    $state['sideEffectLine'] = $state['hasSideEffects']
                        ? min($conditionSideEffectLine, $state['sideEffectLine'])
                        : $conditionSideEffectLine;
                    $state['hasSideEffects'] = true;
                }

                if (! $hasSideEffects && $state['hasSideEffects']) {
                    $sideEffectLine = $state['sideEffectLine'];
                }

                $hasSideEffects = $hasSideEffects || $state['hasSideEffects'];
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
     * Detect syntax that intrinsically causes side effects. Determining whether an
     * arbitrary call is effectful requires semantic analysis and is intentionally out of scope.
     */
    private function intrinsicSideEffectLineInExpression(Expr $expr): ?int
    {
        $nodes          = [$expr];
        $sideEffectLine = null;

        for ($index = 0; isset($nodes[$index]); ++$index) {
            $node = $nodes[$index];

            if ($node instanceof Expr && $this->isIntrinsicSideEffectExpression($node)) {
                $sideEffectLine = $sideEffectLine === null
                    ? $node->getStartLine()
                    : min($sideEffectLine, $node->getStartLine());

                // Descendants start on or after this node's line, so they cannot lower the minimum.
                continue;
            }

            if ($node instanceof FunctionLike || $node instanceof ClassLike) {
                continue;
            }

            foreach ($node->getSubNodeNames() as $subNodeName) {
                $subNode = $node->{$subNodeName};

                if ($subNode instanceof Node) {
                    $nodes[] = $subNode;
                    continue;
                }

                if (! is_array($subNode)) {
                    continue;
                }

                foreach ($subNode as $childNode) {
                    if ($childNode instanceof Node) {
                        $nodes[] = $childNode;
                    }
                }
            }
        }

        return $sideEffectLine;
    }

    private function isIntrinsicSideEffectExpression(Expr $expr): bool
    {
        return $expr instanceof Assign
            || $expr instanceof AssignOp
            || $expr instanceof AssignRef
            || $expr instanceof Eval_
            || $expr instanceof Exit_
            || $expr instanceof Include_
            || $expr instanceof PostDec
            || $expr instanceof PostInc
            || $expr instanceof PreDec
            || $expr instanceof PreInc
            || $expr instanceof Print_
            || $expr instanceof ShellExec
            || $expr instanceof Throw_;
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
