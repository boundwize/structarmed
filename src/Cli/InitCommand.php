<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cli;

use function count;
use function file_exists;
use function file_put_contents;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strtolower;
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

        $target = $basePath . '/structarmed.php';

        if (file_exists($target)) {
            echo "structarmed.php already exists.\n";

            return 0;
        }

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
        return match ($preset) {
            'ddd' => '    ->withPreset(Preset::DDD());',
            'mvc' => '    ->withPreset(Preset::MVC());',
            'psr1' => '    ->withPreset(Preset::PSR1());',
            'psr4' => '    ->withPreset(Preset::PSR4());',
            'all' => "    ->withPresets(\n"
                . "        Preset::PSR1(),\n"
                . "        Preset::PSR12(),\n"
                . "        Preset::PSR4(),\n"
                . "        Preset::DDD(),\n"
                . "        Preset::MVC()\n"
                . "    );",
            default => null,
        };
    }
}
