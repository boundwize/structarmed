<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use Boundwize\StructArmed\Util\InlineHtmlOpeningTagMatcher;
use Boundwize\StructArmed\Util\Path;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
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
use PhpParser\Token;

use function array_key_exists;
use function file_get_contents;
use function is_array;
use function preg_match;
use function str_starts_with;
use function substr;
use function substr_count;
use function token_get_all;
use function trim;

use const T_INLINE_HTML;
use const T_OPEN_TAG;
use const T_OPEN_TAG_WITH_ECHO;

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

    /** @param array<string, FileAnalysis> $analyses */
    public function __construct(private array $analyses = [])
    {
        $normalisedAnalyses = [];
        foreach ($this->analyses as $file => $analysis) {
            $normalisedAnalyses[Path::normalise($file, canonicalise: true)] = $analysis;
        }

        $this->analyses = $normalisedAnalyses;
        $this->parser   = (new ParserFactory())->createForNewestSupportedVersion();
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
            invalidPhpTagLine: $this->invalidPhpTagLineFromTokens($this->tokens[$file]),
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

        foreach (token_get_all($this->contents($file)) as $token) {
            if (! is_array($token)) {
                continue;
            }

            $invalidLine = $this->invalidPhpTagLineForToken($token[0], $token[1], $token[2]);

            if ($invalidLine !== null) {
                return $this->invalidPhpTagLines[$file] = $invalidLine;
            }
        }

        $this->invalidPhpTagLines[$file] = null;

        return null;
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
        if ($id === T_OPEN_TAG_WITH_ECHO) {
            return null;
        }

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

            if ($node instanceof If_ && $this->containsOnlyDeclarations($node)) {
                $declaresSymbols = true;
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

    private function isSymbolDeclaration(Stmt $stmt): bool
    {
        return $stmt instanceof ClassLike
            || $stmt instanceof Function_
            || $stmt instanceof Const_
            || $this->isDefineCall($stmt);
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
