<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cli;

use Boundwize\StructArmed\Config\ConfigLoader;
use RuntimeException;

use function array_map;
use function basename;
use function count;
use function file_put_contents;
use function implode;
use function in_array;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strtolower;
use function strtoupper;
use function substr;

use const FILE_APPEND;

final class InitCommand
{
    /**
     * @param list<string> $arguments
     */
    public function run(array $arguments, string $basePath): int
    {
        $preset  = 'psr4';
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

        $presetConfig = $this->presetConfig($preset);

        if ($presetConfig === null) {
            echo sprintf("Invalid preset: %s\n\n", $preset);
            echo Usage::render();

            return 1;
        }

        // Any discoverable config stops init: a new structarmed.php would shadow
        // an existing structarmed.dist.php, since discovery prefers it.
        try {
            echo sprintf(
                "%s already exists, use `append` instead\n\nvendor/bin/structarmed append --preset=%s\n",
                basename(ConfigLoader::discover($basePath)),
                $preset
            );

            return 0;
        } catch (RuntimeException) {
        }

        $target = $basePath . '/structarmed.php';

        file_put_contents($target, <<<'PHP'
<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\Preset;

return Architecture::define()
PHP);
        file_put_contents($target, "\n" . $presetConfig . "\n", FILE_APPEND);

        echo "Created structarmed.php\n";

        return 0;
    }

    private function presetConfig(string $preset): ?string
    {
        if ($preset === 'all') {
            return "    ->withPresets(\n        Preset::"
                . implode("(),\n        Preset::", array_map(strtoupper(...), PresetName::ALL))
                . "()\n    );";
        }

        if (in_array($preset, PresetName::ALL, true)) {
            return sprintf('    ->withPreset(Preset::%s());', strtoupper($preset));
        }

        return null;
    }
}
