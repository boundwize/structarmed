<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\File;

use Boundwize\StructArmed\Composer\Psr4PathResolver;
use Boundwize\StructArmed\File\PhpFileCollector;
use Boundwize\StructArmed\File\SkipPathMatcher;
use Boundwize\StructArmed\Util\Path;

use function array_unique;
use function array_values;
use function is_dir;
use function json_encode;
use function spl_object_id;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;

final readonly class PhpFileFinder
{
    /**
     * @param list<string>|null $sourcePaths
     */
    public function __construct(
        private ?array $sourcePaths = null,
        private Psr4PathResolver $psr4PathResolver = new Psr4PathResolver(),
        private PhpFileCollector $phpFileCollector = new PhpFileCollector(),
    ) {
    }

    /**
     * @param list<string> $skipPaths
     * @return list<string>
     */
    public function files(string $basePath, array $skipPaths = []): array
    {
        $skipPathMatcher = SkipPathMatcher::compile($basePath, $skipPaths);
        $sourcePaths     = array_values(array_unique($this->sourcePaths ?? $this->psr4PathResolver->paths($basePath)));
        $dataKey         = [
            'basePath'          => $basePath,
            'sourcePaths'       => $sourcePaths,
            'skipPathMatcherId' => spl_object_id($skipPathMatcher),
        ];
        $memoiseKey      = json_encode($dataKey, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

        /** @var array<string, list<string>> $memoisedFiles */
        static $memoisedFiles = [];

        if (isset($memoisedFiles[$memoiseKey])) {
            return $memoisedFiles[$memoiseKey];
        }

        $files = [];

        foreach ($sourcePaths as $sourcePath) {
            $fullPath = Path::resolve($sourcePath, $basePath);

            if (! is_dir($fullPath)) {
                continue;
            }

            foreach ($this->phpFileCollector->collect($fullPath, $skipPathMatcher) as $file) {
                $files[] = $file;
            }
        }

        // ensure nothing duplicated once more
        // avoid inner directory provided by multiple source paths
        return $memoisedFiles[$memoiseKey] = array_values(array_unique($files));
    }
}
