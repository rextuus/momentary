<?php

declare(strict_types=1);

namespace App\Service\Video\Analyze;

use App\Entity\File;
use App\Entity\Medium;
use App\Entity\Video;
use App\Entity\VideoScene;
use App\Repository\VideoRepository;
use App\Repository\VideoSceneRepository;
use App\Service\Face\DeletedMediumGuard;
use App\Service\Face\FaceRecorder;
use App\Service\Face\Provider\FaceRecognitionProviders;
use App\Service\Video\Processing\Exception\VideoGoneException;

/**
 * Erkennt Gesichter in einem Bild (Video-Frame oder Foto) mit dem eingestellten Anbieter.
 *
 * Hier: Frame laden und prüfen, Anbieter aufrufen. Abgleich (FaceAssigner) und Speichern (FaceRecorder) liegen in
 * eigenen Klassen.
 *
 * Ein Video kann gelöscht werden, während ein Frame analysiert wird. Dann bricht die Analyse mit VideoGoneException
 * ab (die Middleware verwirft die Message still) und lässt nichts zurück: weder Gesichtsbild noch angelegte Personen.
 */
class FrameAnalyzer
{
    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly VideoSceneRepository $sceneRepository,
        private readonly FaceRecognitionProviders $providers,
        private readonly FaceRecorder $recorder,
        private readonly DeletedMediumGuard $deletedMediumGuard,
    ) {}

    public function analyzeFrame(int $videoId, string $framePath, int $timestamp): bool
    {
        $video = $this->videoRepository->find($videoId);
        if (!$video) {
            throw VideoGoneException::forId($videoId);
        }

        clearstatcache(true, $framePath);
        if (!file_exists($framePath)) {
            // Beim Löschen eines Videos verschwindet zuerst die Zeile, dann der Frame-Ordner
            if (!$this->deletedMediumGuard->exists($videoId)) {
                throw VideoGoneException::forId($videoId);
            }
            throw new \RuntimeException("Frame file does not exist: {$framePath}");
        }

        // Frames aus einem älteren Durchlauf (anderer Ordner) ignorieren; erlaubt sind Analyse- und Refinement-Frames
        $frameDirPath = $video->getCurrentFrameDirectory();
        if ($frameDirPath && !str_starts_with($framePath, \dirname($frameDirPath) . '/')) {
            return false;
        }

        return $this->analyzeImage($video, $framePath, $timestamp, $this->sceneRepository->findAt($video, $timestamp));
    }

    /**
     * Gesichter in einem Bild erkennen und dem Medium zuordnen (Video-Frame oder Foto).
     *
     * @param File|null $image vorhandene Bilddatei wiederverwenden (Fotos); sonst wird das Bild als Gesichtsbild abgelegt
     */
    public function analyzeImage(
        Medium $medium,
        string $imagePath,
        int $timestamp = 0,
        ?VideoScene $scene = null,
        ?File $image = null,
    ): bool {
        $provider = $this->providers->current();
        $faces = $provider->detectFaces($imagePath, $medium);
        if ($faces === []) {
            return false;
        }

        // Der Aufruf kann Sekunden dauern (Warteschlange im face-service, AWS); so lange kann das Medium gelöscht werden
        $this->deletedMediumGuard->assertExists($medium);
        $this->recorder->record($medium, $provider::getName(), $faces, $imagePath, $timestamp, $scene, $image);

        return true;
    }
}
