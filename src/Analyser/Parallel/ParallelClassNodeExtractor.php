<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use RuntimeException;

use function array_chunk;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function assert;
use function ceil;
use function count;
use function dirname;
use function fclose;
use function feof;
use function fread;
use function fwrite;
use function is_array;
use function is_resource;
use function is_string;
use function max;
use function min;
use function proc_close;
use function serialize;
use function sprintf;
use function stream_get_contents;
use function stream_set_blocking;
use function strpos;
use function substr;
use function substr_count;
use function unserialize;
use function usleep;

use const PHP_BINARY;

final readonly class ParallelClassNodeExtractor
{
    /**
     * @param array<string, string|list<string>> $layers
     * @param array<string, array{pattern: string, excludePattern: string|null}> $layerPatterns
     */
    public function __construct(
        private string $basePath,
        private array $layers,
        private array $layerPatterns,
        private int $workerCount,
    ) {
    }

    /**
     * @param list<string> $files
     * @return list<ClassNode>
     */
    public function extract(array $files, ?ProgressHandlerInterface $progressHandler = null): array
    {
        if ($files === []) {
            return [];
        }

        $workerCount = min($this->workerCount, count($files));
        $chunkSize   = (int) ceil(count($files) / $workerCount);
        $script      = dirname(__DIR__, 3) . '/bin/structarmed.php';
        $processes   = [];

        foreach (array_chunk($files, max(1, $chunkSize)) as $chunk) {
            // phpcs:disable SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly.ReferenceViaFallbackGlobalName
            // to avoid error in test that mock it
            $process = proc_open(
            // phpcs:enable
                [PHP_BINARY, $script, '--internal-worker'],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
            );

            if ($process === false) {
                throw new RuntimeException('Unable to start parallel analysis worker.');
            }

            assert(isset($pipes[0]) && isset($pipes[1]) && isset($pipes[2]));

            fwrite($pipes[0], serialize([
                'basePath'      => $this->basePath,
                'layers'        => $this->layers,
                'layerPatterns' => $this->layerPatterns,
                'files'         => $chunk,
            ]));
            fclose($pipes[0]);

            $stdoutPipe = $pipes[1];
            $stderrPipe = $pipes[2];
            stream_set_blocking($stdoutPipe, false);

            $processes[] = [
                'process'       => $process,
                'files'         => $chunk,
                'filesAdvanced' => 0,
                'stdoutPipe'    => $stdoutPipe,
                'stderrPipe'    => $stderrPipe,
                'stdoutBuffer'  => '',
            ];
        }

        $nodes   = [];
        $failure = null;
        $pending = $processes;

        while ($pending !== []) {
            $anyActivity = false;

            foreach (array_keys($pending) as $key) {
                $stdoutPipe = $pending[$key]['stdoutPipe'];

                $data = fread($stdoutPipe, 8192);
                if ($data !== false && $data !== '') {
                    $pending[$key]['stdoutBuffer'] .= $data;
                    $count                          = substr_count($data, "\n");
                    for ($i = 0; $i < $count; $i++) {
                        $fileIdx = $pending[$key]['filesAdvanced'];
                        if ($fileIdx < count($pending[$key]['files'])) {
                            $progressHandler?->advance($pending[$key]['files'][$fileIdx]);
                            $pending[$key]['filesAdvanced']++;
                        }
                    }

                    $anyActivity = true;
                }

                if (! feof($stdoutPipe)) {
                    continue;
                }

                // Pipe EOF means the worker exited and the OS closed its write end.
                // proc_close() has not been called yet so waitpid() inside it correctly
                // returns the real exit code (no double-waitpid race with proc_get_status).
                fclose($stdoutPipe);

                $procResource = $pending[$key]['process'];
                assert(is_resource($procResource));
                $exitCode = proc_close($procResource);

                $stderrPipe = $pending[$key]['stderrPipe'];

                try {
                    $buffer    = $pending[$key]['stdoutBuffer'];
                    $resultPos = strpos($buffer, 'RESULT:');
                    $headerEnd = $resultPos !== false ? strpos($buffer, "\n", $resultPos) : false;
                    $length    = ($resultPos !== false && $headerEnd !== false)
                        ? (int) substr($buffer, $resultPos + 7, $headerEnd - $resultPos - 7)
                        : 0;
                    $result    = unserialize(substr($buffer, (int) $headerEnd + 1, $length));

                    if (
                        ! is_array($result)
                        || ! isset($result['nodes'])
                        || ! is_array($result['nodes'])
                        || ! array_key_exists('error', $result)
                    ) {
                        throw new RuntimeException('Parallel analysis worker returned an invalid payload.');
                    }

                    $error = $result['error'];

                    if ($error !== null && ! is_string($error)) {
                        throw new RuntimeException('Parallel analysis worker returned an invalid error payload.');
                    }

                    if ($error !== null || $exitCode !== 0) {
                        $stderr = is_resource($stderrPipe) ? (string) stream_get_contents($stderrPipe) : '';

                        throw new RuntimeException(sprintf(
                            "Parallel analysis worker failed: %s%s",
                            $error ?? 'unknown error',
                            $stderr !== '' ? "\n" . $stderr : ''
                        ));
                    }

                    /** @var list<ClassNode> $workerNodes */
                    $workerNodes = $result['nodes'];
                    $nodes       = array_merge($nodes, $workerNodes);
                } catch (RuntimeException $runtimeException) {
                    $failure ??= $runtimeException->getMessage();
                } finally {
                    if (is_resource($stderrPipe)) {
                        fclose($stderrPipe);
                    }
                }

                unset($pending[$key]);
                $anyActivity = true;
            }

            if (! $anyActivity) {
                usleep(5000);
            }
        }

        if ($failure !== null) {
            throw new RuntimeException($failure);
        }

        return $nodes;
    }
}
