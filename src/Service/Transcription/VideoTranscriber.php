<?php

declare(strict_types=1);

namespace App\Service\Transcription;

use App\Entity\Video;
use App\Entity\VideoTranscript;
use App\Service\Storage\StoragePathProvider;
use App\Service\VideoSearchIndex;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Transkribiert den Ton eines Videos: Ton herauslösen, Whisper, Halluzinationen filtern, speichern,
 * optional Tags ableiten, Untertitel für Jellyfin schreiben und den Suchindex aktualisieren.
 */
class VideoTranscriber
{
    /** Fortschritt höchstens so oft speichern */
    private const float PROGRESS_INTERVAL_SECONDS = 5.0;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StoragePathProvider $pathProvider,
        private readonly TranscriptionSettingsProvider $settingsProvider,
        private readonly AudioExtractor $audioExtractor,
        private readonly WhisperRunner $whisper,
        private readonly TranscriptSegmentFilter $filter,
        private readonly TranscriptTagger $tagger,
        private readonly VideoSearchIndex $searchIndex,
        private readonly JellyfinSubtitleExporter $subtitleExporter,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%/var/transcription')]
        private readonly string $workDir,
    ) {}

    public function transcribe(Video $video): VideoTranscript
    {
        $transcript = $video->getTranscript() ?? new VideoTranscript($video);
        $this->entityManager->persist($transcript);
        $settings = $this->settingsProvider->get();
        $wavPath = sprintf('%s/video_%d.wav', $this->workDir, (int) $video->getId());

        try {
            $file = $video->getConvertedFile() ?? $video->getSourceFile();
            $videoPath = $file !== null ? $this->pathProvider->getAbsolutePath($file) : null;
            if ($videoPath === null || !is_file($videoPath)) {
                throw new TranscriptionException('Die Videodatei ist nicht mehr vorhanden.');
            }

            new Filesystem()->mkdir($this->workDir);
            $transcript->start($settings->getModel(), 0.0);
            $this->entityManager->flush();

            $audioSeconds = $this->audioExtractor->extract($videoPath, $wavPath);
            $transcript->start($settings->getModel(), $audioSeconds);
            $this->entityManager->flush();

            $lastSaved = microtime(true);
            $result = $this->whisper->run($wavPath, $settings, function (float $seconds) use (
                $transcript,
                &$lastSaved,
            ): void {
                $transcript->reportProgress($seconds);
                if ((microtime(true) - $lastSaved) >= self::PROGRESS_INTERVAL_SECONDS) {
                    $this->entityManager->flush();
                    $lastSaved = microtime(true);
                }
            });

            $transcript->complete($result['language'], $this->filter->filter($result['segments']));
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $transcript->fail($e->getMessage());
            $this->entityManager->flush();
            $this->logger->error(sprintf(
                'Transkription von Video %d fehlgeschlagen: %s',
                (int) $video->getId(),
                $e->getMessage(),
            ));

            return $transcript;
        } finally {
            new Filesystem()->remove($wavPath);
        }

        $this->afterTranscription($transcript, $settings->isDeriveTags());

        return $transcript;
    }

    /**
     * Tags und Suchindex sind Zugaben: scheitern sie, bleibt das Transkript trotzdem gültig.
     */
    private function afterTranscription(VideoTranscript $transcript, bool $deriveTags): void
    {
        $video = $transcript->getVideo();

        if ($deriveTags && $transcript->getSegments()->count() > 0) {
            try {
                $this->tagger->tag($transcript);
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf(
                    'Tags aus Transkript für Video %d: %s',
                    (int) $video->getId(),
                    $e->getMessage(),
                ));
            }
        }

        try {
            $this->subtitleExporter->export($transcript);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('Untertitel für Video %d: %s', (int) $video->getId(), $e->getMessage()));
        }

        try {
            $this->searchIndex->index($video);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('Suchindex für Video %d: %s', (int) $video->getId(), $e->getMessage()));
        }
    }
}
