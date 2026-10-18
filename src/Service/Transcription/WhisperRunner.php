<?php

declare(strict_types=1);

namespace App\Service\Transcription;

use App\Entity\TranscriptionSettings;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Führt video-analyzer/python/transcribe.py (faster-whisper) aus.
 */
class WhisperRunner
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(WHISPER_MODELS_DIR)%')]
        private readonly string $modelsDir,
        #[Autowire('%env(PYTHON_BINARY)%')]
        private readonly string $pythonBinary = '/usr/bin/python3',
    ) {}

    /**
     * @param callable(float):void|null $onProgress erhält die bisher verarbeiteten Sekunden Ton
     *
     * @return array{language: ?string, duration: float, segments: list<array{start: float, end: float, text: string, avg_logprob: float, no_speech_prob: float}>}
     */
    public function run(string $wavPath, TranscriptionSettings $settings, ?callable $onProgress = null): array
    {
        $command = [
            is_file($this->pythonBinary) ? $this->pythonBinary : 'python3',
            $this->projectDir . '/video-analyzer/python/transcribe.py',
            '--audio',
            $wavPath,
            '--model',
            $settings->getModel(),
            '--threads',
            (string) $settings->getThreads(),
            '--models-dir',
            $this->modelsDir,
        ];
        if ($settings->getLanguage() !== null) {
            $command[] = '--language';
            $command[] = $settings->getLanguage();
        }

        // Modell-Download: Hugging Face legt Locks/Metadaten unter HF_HOME ab (das Home von www-data ist nicht
        // beschreibbar); der klassische HTTP-Download ist robuster als das Xet-Protokoll
        $process = new Process($command, env: [
            'HF_HOME' => $this->modelsDir . '/hf-home',
            'HF_HUB_DISABLE_XET' => '1',
        ]);
        // Lange Videos auf schwacher Hardware: mehrere Stunden sind möglich
        $process->setTimeout(null);
        $process->setIdleTimeout(1800);
        $process->start();

        $stderr = '';
        foreach ($process->getIterator(Process::ITER_SKIP_OUT) as $chunk) {
            $stderr .= $chunk;
            if ($onProgress !== null && preg_match_all('/^PROGRESS ([\d.]+) /m', $chunk, $matches) > 0) {
                $onProgress((float) end($matches[1]));
            }
        }
        $process->wait();

        if (!$process->isSuccessful()) {
            throw new TranscriptionException(sprintf(
                'Whisper ist fehlgeschlagen (Exit %d): %s',
                (int) $process->getExitCode(),
                self::lastLines($stderr),
            ));
        }

        $data = json_decode($process->getOutput(), true);
        if (!\is_array($data) || !\is_array($data['segments'] ?? null)) {
            throw new TranscriptionException('Whisper hat kein gültiges JSON geliefert: ' . self::lastLines($stderr));
        }

        return [
            'language' => isset($data['language']) ? (string) $data['language'] : null,
            'duration' => (float) ($data['duration'] ?? 0.0),
            'segments' => array_values(array_map(static fn(array $s): array => [
                'start' => (float) ($s['start'] ?? 0.0),
                'end' => (float) ($s['end'] ?? 0.0),
                'text' => (string) ($s['text'] ?? ''),
                'avg_logprob' => (float) ($s['avg_logprob'] ?? 0.0),
                'no_speech_prob' => (float) ($s['no_speech_prob'] ?? 0.0),
            ], $data['segments'])),
        ];
    }

    private static function lastLines(string $output): string
    {
        $lines = array_filter(
            explode("\n", trim($output)),
            static fn(string $l): bool => !str_starts_with($l, 'PROGRESS'),
        );

        return implode("\n", \array_slice($lines, -8));
    }
}
