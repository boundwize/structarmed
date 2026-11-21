<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cli;

use Boundwize\StructArmed\Config\ConfigLoader;
use PhpToken;
use RuntimeException;

use function basename;
use function count;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function in_array;
use function preg_match;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strtolower;
use function strtoupper;
use function substr;

use const PHP_EOL;
use const T_WHITESPACE;

final class AppendCommand
{
    private const PRESET_IMPORT = 'use Boundwize\\StructArmed\\Preset\\Preset;';

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

        $content   = (string) file_get_contents($configFile);
        $namespace = str_contains($content, self::PRESET_IMPORT) ? '' : '\\Boundwize\\StructArmed\\Preset\\';
        $appended  = [];

        foreach ($preset === 'all' ? PresetName::ALL : [$preset] as $name) {
            $factory = strtoupper($name);

            // Applying the same preset twice is not a supported configuration.
            if (preg_match('/\bPreset::' . $factory . '\s*\(/i', $content) === 1) {
                continue;
            }

            $appended[] = sprintf('%sPreset::%s()', $namespace, $factory);
        }

        if ($appended === []) {
            echo sprintf("%s already has the %s preset.\n", basename($configFile), $preset);

            return 0;
        }

        // The last `;` ends the statement that returns the Architecture, so the
        // new calls continue its fluent chain. Editing the token text keeps the
        // rest of the file byte-for-byte.
        $tokens = PhpToken::tokenize($content);
        $index  = count($tokens) - 1;

        while ($index > 0 && $tokens[$index]->text !== ';') {
            $index--;
        }

        if ($index <= 0) {
            echo sprintf("Error: could not find a statement to append to in %s.\n", basename($configFile));

            return 1;
        }

        do {
            $index--;
        } while ($tokens[$index]->id === T_WHITESPACE);

        foreach ($appended as $call) {
            $tokens[$index]->text .= sprintf("\n    ->withPreset(%s)", $call);
        }

        file_put_contents($configFile, implode('', $tokens));

        echo sprintf("Appended %s to %s\n", implode(', ', $appended), basename($configFile));

        return 0;
    }
}
