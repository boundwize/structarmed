<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cli;

use Boundwize\StructArmed\Config\ConfigLoader;
use PhpParser\ConstExprEvaluationException;
use PhpParser\ConstExprEvaluator;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\MagicConst\Dir;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RuntimeException;

use function basename;
use function count;
use function dirname;
use function end;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function in_array;
use function is_string;
use function realpath;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strspn;
use function strtolower;
use function strtoupper;
use function substr;
use function substr_replace;

use const PHP_EOL;
use const T_WHITESPACE;

final class AppendCommand
{
    private const PRESET_NAMESPACE = 'Boundwize\\StructArmed\\Preset\\';

    // The command only reads and writes this name as config source, so the Cli layer
    // stays independent of the Preset layer.
    private const PRESET_CLASS = self::PRESET_NAMESPACE . 'Preset';

    /**
     * @param list<string> $arguments
     */
    public function run(array $arguments, string $basePath): int
    {
        $preset  = '';
        $counter = count($arguments);

        for ($i = 0; $i < $counter; $i++) {
            $argument = $arguments[$i];

            if (str_starts_with($argument, '--preset=')) {
                $preset = strtolower(substr($argument, strlen('--preset=')));
                continue;
            }

            if ($argument === '--preset') {
                $preset = strtolower($arguments[++$i] ?? '');
                continue;
            }

            echo sprintf("Unknown option: %s\n\n", $argument);
            echo Usage::render();

            return 1;
        }

        if ($preset !== 'all' && ! in_array($preset, PresetName::ALL, true)) {
            echo sprintf("Invalid preset: %s\n\n", $preset);
            echo Usage::render();

            return 1;
        }

        try {
            $configFile = ConfigLoader::discover($basePath);
        } catch (RuntimeException $runtimeException) {
            echo 'Error: ' . $runtimeException->getMessage() . PHP_EOL;

            return 1;
        }

        $content      = (string) file_get_contents($configFile);
        $parser       = (new ParserFactory())->createForNewestSupportedVersion();
        $nameResolver = new NameResolver();

        // Resolved names let an aliased or a fully qualified Preset reference match as well.
        try {
            $stmts = (new NodeTraverser($nameResolver))->traverse($parser->parse($content) ?? []);
        } catch (Error $error) {
            echo sprintf("Error: could not parse %s: %s.\n", basename($configFile), $error->getMessage());

            return 1;
        }

        $shortName = $nameResolver->getNameContext()->getShortName(self::PRESET_CLASS, Use_::TYPE_NORMAL);
        $class     = $shortName->isUnqualified() ? $shortName->toString() : '\\' . self::PRESET_CLASS;
        $applied   = $this->appliedPresets($stmts, (string) realpath($configFile));
        $appended  = [];

        foreach ($preset === 'all' ? PresetName::ALL : [$preset] as $name) {
            // Applying the same preset twice is not a supported configuration.
            if (isset($applied[$name])) {
                continue;
            }

            $appended[] = sprintf('%s::%s()', $class, strtoupper($name));
        }

        if ($appended === []) {
            echo sprintf("%s already has the %s preset.\n", basename($configFile), $preset);

            return 0;
        }

        $target = $this->appendTarget($stmts);

        if ($target === null) {
            echo sprintf("Error: could not find a statement to append to in %s.\n", basename($configFile));

            return 1;
        }

        [$node, $variable] = $target;

        // The new code goes after the last token before the `;` of the statement or the `}` of the
        // closure, so a trailing comment keeps its line, and inserting into the source keeps the
        // rest of the file byte-for-byte.
        $tokens = $parser->getTokens();
        $index  = $node->getEndTokenPos();

        do {
            $index--;
        } while ($tokens[$index]->id === T_WHITESPACE);

        $line   = explode("\n", $content)[$node->getStartLine() - 1] ?? '';
        $indent = substr($line, 0, strspn($line, " \t")) . '    ';
        $calls  = [];

        foreach ($appended as $call) {
            $calls[] = sprintf('->withPreset(%s)', $call);
        }

        // A statement continues its fluent chain one level deeper. A closure gets a statement of
        // its own, which later runs continue the same way.
        $code = $node instanceof Closure
            ? ($node->stmts === [] ? "\n" : "\n\n") . $indent . $variable . implode("\n    " . $indent, $calls) . ';'
            : "\n" . $indent . implode("\n" . $indent, $calls);

        file_put_contents($configFile, substr_replace($content, $code, $tokens[$index]->getEndPos(), 0));

        echo sprintf("Appended %s to %s\n", implode(', ', $appended), basename($configFile));

        return 0;
    }

    /**
     * Preset factories passed to withPreset() or withPresets() in a config and in the files it
     * imports, keyed by lower-cased factory name.
     *
     * @param Node[]              $stmts     statements of $file, with class names resolved
     * @param array<string, true> $importing files being followed, as imports may be circular
     * @return array<string, true>
     */
    private function appliedPresets(array $stmts, string $file, array $importing = []): array
    {
        $nodeFinder       = new NodeFinder();
        $importing[$file] = true;
        $arguments        = [];
        $applied          = [];

        foreach ($nodeFinder->findInstanceOf($stmts, MethodCall::class) as $methodCall) {
            $method   = $methodCall->name instanceof Identifier ? $methodCall->name->toLowerString() : '';
            $argument = $methodCall->args[0] ?? null;

            if ($method === 'withpreset' || $method === 'withpresets') {
                $arguments = [...$arguments, ...$methodCall->args];
            }

            if ($method === 'import' && $argument instanceof Arg) {
                $applied += $this->importedPresets($argument->value, $file, $importing);
            }
        }

        // A variable can carry presets created anywhere in the config, which only running it
        // would tell, so every factory call of the file counts then.
        $scope = $nodeFinder->findFirstInstanceOf($arguments, Variable::class) instanceof Variable
            ? $stmts
            : $arguments;

        foreach ($nodeFinder->findInstanceOf($scope, StaticCall::class) as $staticCall) {
            if (
                $staticCall->class instanceof Name
                && $staticCall->name instanceof Identifier
                && $staticCall->class->toLowerString() === strtolower(self::PRESET_CLASS)
            ) {
                $applied[$staticCall->name->toLowerString()] = true;
            }
        }

        return $applied;
    }

    /**
     * Presets of a file passed to import(), as far as its path is known without running the config.
     *
     * @param array<string, true> $importing
     * @return array<string, true>
     */
    private function importedPresets(Expr $path, string $file, array $importing): array
    {
        $constExprEvaluator = new ConstExprEvaluator(
            static fn (Expr $expr): string => $expr instanceof Dir
                ? dirname($file)
                : throw new ConstExprEvaluationException('Only known when the config runs.')
        );

        try {
            $value    = $constExprEvaluator->evaluateSilently($path);
            $imported = is_string($value) ? realpath($value) : false;

            if ($imported === false || isset($importing[$imported])) {
                return [];
            }

            $stmts = (new NodeTraverser(new NameResolver()))->traverse(
                (new ParserFactory())->createForNewestSupportedVersion()
                    ->parse((string) file_get_contents($imported)) ?? []
            );
        } catch (ConstExprEvaluationException | Error) {
            // a path or a file that cannot be read this way says nothing about its presets
            return [];
        }

        return $this->appliedPresets($stmts, $imported, $importing);
    }

    /**
     * Where the new calls go: the statement whose fluent chain configures the Architecture, which
     * is the returned one or the last one of a returned closure, the way files written for
     * Architecture::import() configure it. A closure that ends otherwise is the target itself,
     * along with the parameter that a statement added to it calls.
     *
     * @param Node[] $stmts
     * @return array{Return_|Expression|Closure, string}|null
     */
    private function appendTarget(array $stmts): ?array
    {
        $return = null;

        foreach ($stmts as $stmt) {
            // a namespaced config keeps its statements inside the namespace node
            foreach ($stmt instanceof Namespace_ ? $stmt->stmts : [$stmt] as $candidate) {
                if ($candidate instanceof Return_ && $candidate->expr instanceof Expr) {
                    $return = $candidate;
                }
            }
        }

        if (! $return instanceof Return_) {
            return null;
        }

        if (! $return->expr instanceof Closure) {
            return [$return, ''];
        }

        $closure = $return->expr;
        $last    = end($closure->stmts);

        if (
            ($last instanceof Expression && $last->expr instanceof MethodCall)
            || ($last instanceof Return_ && $last->expr instanceof Expr)
        ) {
            return [$last, ''];
        }

        $variable = ($closure->params[0] ?? null)?->var;

        // nothing added after a bare `return;` would run
        if ($last instanceof Return_ || ! $variable instanceof Variable || ! is_string($variable->name)) {
            return null;
        }

        return [$closure, '$' . $variable->name];
    }
}
