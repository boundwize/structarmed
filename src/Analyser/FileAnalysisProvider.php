<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

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
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\Token;

use function array_key_exists;
use function file_get_contents;
use function is_array;
use function preg_match;
use function str_starts_with;
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

    /** @var array<string, string> */
    private array $contents = [];

    /** @var array<string, int|null> */
    private array $invalidPhpTagLines = [];

    /** @param array<string, FileAnalysis> $analyses */
    public function __construct(private array $analyses = [])
    {
        $this->parser   = (new ParserFactory())->createForNewestSupportedVersion();
    }

    public function analyse(string $file): FileAnalysis
    {
        if (isset($this->analyses[$file])) {
            return $this->analyses[$file];
        }

        $code        = $this->contents($file);
        $ast         = null;
        $hasValidAst = true;

        try {
            $ast = $this->parser->parse($code);
        } catch (Error) {
            $hasValidAst = false;
        }

        $this->asts[$file] = $ast;
        $fileState         = $hasValidAst ? $this->fileState($ast ?? []) : [
            'declaresSymbols' => false,
            'hasSideEffects'  => false,
            'sideEffectLine'  => 1,
        ];

        $fileAnalysis = new FileAnalysis(
            file: $file,
            hasUtf8Bom: str_starts_with($code, "\xEF\xBB\xBF"),
            hasValidUtf8: preg_match('//u', $code) === 1,
            invalidPhpTagLine: $this->invalidPhpTagLineFromTokens($this->parser->getTokens()),
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
    public function ast(string $file): ?array
    {
        $this->analyse($file);

        return $this->asts[$file] ?? null;
    }

    public function releaseAst(string $file): void
    {
        unset($this->asts[$file]);
    }

    public function hasUtf8Bom(string $file): bool
    {
        return $this->analyses[$file]->hasUtf8Bom ?? str_starts_with($this->contents($file), "\xEF\xBB\xBF");
    }

    public function hasValidUtf8(string $file): bool
    {
        return $this->analyses[$file]->hasValidUtf8 ?? preg_match('//u', $this->contents($file)) === 1;
    }

    public function invalidPhpTagLine(string $file): ?int
    {
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

            if ($this->isInvalidPhpTag($token[0], $token[1])) {
                return $this->invalidPhpTagLines[$file] = $token[2];
            }
        }

        $this->invalidPhpTagLines[$file] = null;

        return null;
    }

    /** @param array<Token> $tokens */
    private function invalidPhpTagLineFromTokens(array $tokens): ?int
    {
        foreach ($tokens as $token) {
            if ($this->isInvalidPhpTag($token->id, $token->text)) {
                return $token->line;
            }
        }

        return null;
    }

    private function isInvalidPhpTag(int $id, string $text): bool
    {
        if ($id === T_OPEN_TAG_WITH_ECHO) {
            return false;
        }

        if ($id === T_OPEN_TAG && preg_match('/^<\?php(?:\s|$)/', $text) === 1) {
            return false;
        }

        return $id === T_OPEN_TAG
            || ($id === T_INLINE_HTML && preg_match('/<\?(?!php(?:\s|$)|=)/', $text) === 1);
    }

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
