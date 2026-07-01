<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Composer;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Composer\Psr4PathResolver;
use Boundwize\StructArmed\Rule\ComposerJsonRuleInterface;
use Boundwize\StructArmed\Rule\Fixer\JsonRecast\AbstractJsonRecastFixableRule;
use Boundwize\StructArmed\Rule\Fixer\JsonRecast\ObjectItemNode\RemoveMissingPsr4PathVisitor;
use Boundwize\StructArmed\Rule\RuleViolation;

use function dirname;
use function file_exists;
use function implode;
use function is_dir;
use function rtrim;
use function sprintf;

final readonly class Psr4DirectoryExistsRule extends AbstractJsonRecastFixableRule implements ComposerJsonRuleInterface
{
    public function __construct(
        private Psr4PathResolver $psr4PathResolver = new Psr4PathResolver(),
    ) {
    }

    public function evaluateProject(string $basePath, Architecture $architecture, array $skipPaths = []): ?RuleViolation
    {
        $composerFile = rtrim($basePath, '/') . '/composer.json';

        if (! file_exists($composerFile)) {
            return $this->violation(
                'composer.json was not found',
                $composerFile
            );
        }

        $composer = $this->psr4PathResolver->composerConfig($basePath);

        if ($composer === null) {
            return $this->violation(
                'composer.json is not valid JSON',
                $composerFile
            );
        }

        $nonExistentPaths = [];

        foreach ($this->psr4PathResolver->paths($basePath) as $autoloadPath) {
            if (! is_dir(rtrim($basePath, '/') . '/' . $autoloadPath)) {
                $nonExistentPaths[] = $autoloadPath;
            }
        }

        if ($nonExistentPaths === []) {
            return null;
        }

        return $this->violation(
            sprintf(
                'PSR-4 source path(s) [%s] declared in composer.json do not exist on disk',
                implode(', ', $nonExistentPaths)
            ),
            $composerFile
        );
    }

    private function violation(string $message, string $file): RuleViolation
    {
        return new RuleViolation(
            message:   $message,
            file:      $file,
            line:      1,
            className: '',
            layer:     'Source',
        );
    }

    protected function createFixerVisitor(RuleViolation $ruleViolation): RemoveMissingPsr4PathVisitor
    {
        return new RemoveMissingPsr4PathVisitor(dirname($ruleViolation->file));
    }
}
