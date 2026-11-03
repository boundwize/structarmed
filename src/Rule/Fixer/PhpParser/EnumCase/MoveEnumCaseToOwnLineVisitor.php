<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\PhpParser\EnumCase;

use Boundwize\StructArmed\Rule\Fixer\PhpParser\AbstractTokenAwareVisitor;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\PhpParserFixerProcessor;
use Boundwize\StructArmed\Util\PhpParser\EnumCaseLine;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\EnumCase;

use function str_contains;
use function strrpos;
use function strspn;
use function substr;

use const T_WHITESPACE;

/**
 * Moves an enum case onto its own line by inserting line breaks into the
 * whitespace around it; no node is re-printed, so the case, its backing
 * value, and the rest of the enum keep their formatting.
 *
 * Comments between two members belong to the member after them, as
 * PHP-Parser attaches them, so the break goes before those comments. A case
 * split off a line it shared with other cases and owning no comment of its
 * own also receives the ordinary comments written above that group, which
 * described every case on the line; a doc comment is never copied, as it is
 * reflection-visible metadata of the case it precedes.
 *
 * Each fix adds a new line after the code preceding its case (the previous
 * member or the enum's `{`), so the case and the comments belonging to it
 * move down. It also adds a new line after the case itself when a constant,
 * a method, or the closing brace follows; when another case follows, that
 * case is flagged too and its own fix adds the new line in front of it. A new
 * line is only added where there is none yet, so fixing an already fixed file
 * changes nothing.
 */
final class MoveEnumCaseToOwnLineVisitor extends AbstractTokenAwareVisitor
{
    public function __construct(
        private readonly string $className,
        private readonly string $caseName,
    ) {
    }

    public function enterNode(Node $node): ?Node
    {
        if (! $node instanceof Enum_ || $node->namespacedName?->toString() !== $this->className) {
            return null;
        }

        $hasChanged = false;

        foreach ($node->stmts as $index => $member) {
            if (! $member instanceof EnumCase || $member->name->toString() !== $this->caseName) {
                continue;
            }

            if ($this->separate($node, $index)) {
                $hasChanged = true;
            }
        }

        return $hasChanged ? $node : null;
    }

    /** Whether a line break was inserted; an already separated case is left alone. */
    private function separate(Enum_ $enum, int $index): bool
    {
        $members      = $enum->stmts;
        $next         = $members[$index + 1] ?? null;
        $precedingGap = EnumCaseLine::precedingGap($this->tokens, $members[$index]);

        // A case right after this one on the same line is flagged as well,
        // and the break before it is its own fix's.
        $followingGap = $next instanceof EnumCase
            ? null
            : EnumCaseLine::followingGap($this->tokens, $members[$index]);

        if ($precedingGap === null && $followingGap === null) {
            return false;
        }

        $eol          = $this->lineEnding();
        $enumIndent   = $this->lineIndent($enum->getStartTokenPos());
        $memberIndent = $this->memberIndent($members, $enumIndent);

        if ($precedingGap !== null) {
            $this->insertBreak(
                $precedingGap,
                $eol . $memberIndent,
                $eol . $memberIndent,
                $this->mirroredComments($members, $index)
            );
        }

        // The following code is another member or the enum's closing brace;
        // comments trailing the last member, held by a Nop, precede the brace.
        if ($followingGap !== null) {
            $this->insertBreak(
                $followingGap,
                $eol . $memberIndent,
                $eol . ($followingGap[1] === $enum->getEndTokenPos() ? $enumIndent : $memberIndent),
                []
            );
        }

        return true;
    }

    /**
     * Rewrites the gap between two code tokens as one comment per line, the
     * mirrored ones first, followed by the break before the second token.
     *
     * @param array{int, int} $gap
     * @param list<string>    $mirroredComments
     */
    private function insertBreak(array $gap, string $commentBreak, string $finalBreak, array $mirroredComments): void
    {
        [$previous, $next] = $gap;

        $text = '';

        foreach ($mirroredComments as $mirroredComment) {
            $text .= $commentBreak . $mirroredComment;
        }

        for ($position = $previous + 1; $position < $next; $position++) {
            if ($this->tokens[$position]->id !== T_WHITESPACE) {
                $text .= $commentBreak . $this->tokens[$position]->text;
            }

            $this->tokens[$position]->text = '';
        }

        $this->tokens[$previous]->text .= $text . $finalBreak;
    }

    /**
     * The ordinary comments written above the cases sharing a line with the
     * given case, when it owns none.
     *
     * @param array<Stmt> $members
     * @return list<string>
     */
    private function mirroredComments(array $members, int $index): array
    {
        if ($members[$index]->getComments() !== []) {
            return [];
        }

        // Walk back to the case on the original line that owns comments, or
        // to the first case of that line.
        $leader = $index;

        while (
            $leader > 0
            && $members[$leader]->getComments() === []
            && $members[$leader - 1] instanceof EnumCase
            && $members[$leader - 1]->getEndLine() === $members[$leader]->getStartLine()
        ) {
            $leader--;
        }

        if ($leader === $index) {
            return [];
        }

        // Only a comment on a line of its own describes the group; one after
        // the code before the group trails that code.
        $previous = $members[$leader]->getStartTokenPos() - 1;

        while (EnumCaseLine::isTrivia($this->tokens[$previous])) {
            $previous--;
        }

        $comments = [];

        foreach ($members[$leader]->getComments() as $comment) {
            if (! $comment instanceof Doc && $comment->getStartLine() > $this->tokens[$previous]->line) {
                $comments[] = $comment->getText();
            }
        }

        return $comments;
    }

    /**
     * The indentation of the first member starting a line of its own, or one
     * level deeper than the enum when every member shares a line.
     *
     * @param array<Stmt> $members
     */
    private function memberIndent(array $members, string $enumIndent): string
    {
        foreach ($members as $member) {
            if (EnumCaseLine::precedingGap($this->tokens, $member) === null) {
                return $this->lineIndent($member->getStartTokenPos());
            }
        }

        return $enumIndent . (str_contains($enumIndent, "\t") ? "\t" : PhpParserFixerProcessor::INDENT);
    }

    /** The whitespace starting the line the token at the given position is on. */
    private function lineIndent(int $position): string
    {
        $line = '';

        for ($index = $position - 1; $index >= 0; $index--) {
            $text    = $this->tokens[$index]->text;
            $newline = strrpos($text, "\n");

            if ($newline !== false) {
                $line = substr($text, $newline + 1) . $line;

                break;
            }

            $line = $text . $line;
        }

        return substr($line, 0, strspn($line, " \t"));
    }

    private function lineEnding(): string
    {
        foreach ($this->tokens as $token) {
            if (str_contains($token->text, "\n")) {
                return str_contains($token->text, "\r\n") ? "\r\n" : "\n";
            }
        }

        return "\n";
    }
}
