<?php

declare(strict_types=1);

namespace App\Service\Transcription;

use Symfony\Component\Process\Process;

/**
 * Löst den Ton eines Videos als 16-kHz-Mono-WAV heraus, das Format, das Whisper intern verwendet.
 */
class AudioExtractor
{
    /**
     * @param int|null $maxSeconds nur den Anfang herauslösen (Benchmark)
     *
     * @return float Länge des Tons in Sekunden
     */
    public function extract(string $videoPath, string $wavPath, ?int $maxSeconds = null): float
    {
        $limit = $maxSeconds !== null ? ['-t', (string) $maxSeconds] : [];
        $process = new Process([
            'ffmpeg',
            '-v',
            'error',
            '-y',
            '-i',
            $videoPath,
            ...$limit,
            '-vn',
            '-ac',
            '1',
            '-ar',
            '16000',
            '-c:a',
            'pcm_s16le',
            $wavPath,
        ]);
        $process->setTimeout(3600);
        $process->run();

        if (!$process->isSuccessful() || !is_file($wavPath)) {
            throw new TranscriptionException(sprintf(
                'Ton konnte nicht herausgelöst werden (hat das Video eine Tonspur?): %s',
                trim($process->getErrorOutput()),
            ));
        }

        // PCM16 mono 16 kHz: 32 000 Byte pro Sekunde nach dem 44-Byte-Header
        return max(0.0, ((int) filesize($wavPath) - 44) / 32000);
    }
}
