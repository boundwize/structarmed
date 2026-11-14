<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Report\Reports;

use Boundwize\StructArmed\Report\ReportInterface;
use Boundwize\StructArmed\Rule\RuleViolationCollection;
use Boundwize\StructArmed\Util\Path;

use function sprintf;
use function str_replace;
use function str_starts_with;

use const PHP_EOL;

/**
 * Emits one GitHub Actions workflow command per violation, so each one shows
 * up as an inline annotation on the pull request. The console report follows,
 * so the annotation's "View details" link lands on a readable job log.
 *
 * The runner resolves annotation paths against its workspace (the repository
 * root), so paths are made relative to it when the project lives inside it,
 * e.g. "packages/api/src/Service.php" for a project in "packages/api".
 *
 * @see https://docs.github.com/en/actions/reference/workflow-commands-for-github-actions#setting-an-error-message
 */
final readonly class GithubReport implements ReportInterface
{
    public function __construct(private string $basePath, private ?string $workspacePath = null)
    {
    }

    public function render(RuleViolationCollection $ruleViolationCollection, float $elapsedSeconds): string
    {
        $normalisedBasePath = Path::normalise($this->basePath, canonicalise: true);
        $normalisedRootPath = $this->resolveRootPath($normalisedBasePath);
        $output             = '';

        foreach ($ruleViolationCollection as $violation) {
            $output .= sprintf(
                '::error file=%s,line=%d,title=%s::%s' . PHP_EOL,
                $this->escapeProperty(Path::relativeTo(
                    Path::resolve($violation->file, $normalisedBasePath),
                    $normalisedRootPath
                )),
                $violation->line,
                $this->escapeProperty($violation->ruleKey),
                $this->escapeData($violation->message)
            );
        }

        return $output . (new ConsoleReport())->render($ruleViolationCollection, $elapsedSeconds);
    }

    /**
     * A project outside the workspace cannot be annotated, so its paths stay
     * relative to the project base.
     */
    private function resolveRootPath(string $normalisedBasePath): string
    {
        if ($this->workspacePath === null) {
            return $normalisedBasePath;
        }

        $normalisedWorkspacePath = Path::normalise($this->workspacePath, canonicalise: true);

        if (str_starts_with($normalisedBasePath . '/', $normalisedWorkspacePath . '/')) {
            return $normalisedWorkspacePath;
        }

        return $normalisedBasePath;
    }

    private function escapeData(string $value): string
    {
        return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);
    }

    private function escapeProperty(string $value): string
    {
        return str_replace(['%', "\r", "\n", ':', ','], ['%25', '%0D', '%0A', '%3A', '%2C'], $value);
    }
}
