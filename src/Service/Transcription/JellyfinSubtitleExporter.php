<?php

declare(strict_types=1);

namespace App\Service\Transcription;

use App\Entity\VideoTranscript;
use App\Enum\TranscriptionStatus;
use App\Service\Jellyfin\JellyfinUploadService;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Legt das Transkript als Untertitel neben das an Jellyfin exportierte Video ("<Name>.<Sprache>.srt") und lässt
 * Jellyfin den Eintrag neu einlesen. Jellyfin bietet die Untertitel dann im Player an.
 */
class JellyfinSubtitleExporter
{
    public function __construct(
        private readonly SrtWriter $srtWriter,
        private readonly JellyfinUploadService $jellyfin,
    ) {}

    /**
     * @return string|null Pfad der geschriebenen SRT-Datei, null wenn das Video (noch) nicht in Jellyfin liegt
     */
    public function export(VideoTranscript $transcript): ?string
    {
        $video = $transcript->getVideo();
        $videoPath = $video->getJellyfinPath();
        if ($videoPath === null || $videoPath === '' || !is_dir(\dirname($videoPath))) {
            return null;
        }

        $base = substr($videoPath, 0, -\strlen(pathinfo($videoPath, PATHINFO_EXTENSION)) - 1);
        $filesystem = new Filesystem();
        // Untertitel eines früheren Laufs (ggf. andere Sprache) entfernen
        $filesystem->remove(glob($base . '.*.srt') ?: []);

        $path = null;
        if ($transcript->getStatus() === TranscriptionStatus::DONE && !$transcript->getSegments()->isEmpty()) {
            $path = sprintf('%s.%s.srt', $base, $transcript->getLanguage() ?? 'de');
            $filesystem->dumpFile($path, $this->srtWriter->write($transcript->getSegments()));
            // Jellyfin läuft in einem eigenen Container und muss die Datei lesen können
            $filesystem->chmod($path, 0o666);
        }

        $itemId = $video->getJellyfinItemId();
        $itemId !== null ? $this->jellyfin->refreshItem($itemId) : $this->jellyfin->triggerScan();

        return $path;
    }
}
