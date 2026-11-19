<?php

declare(strict_types=1);

namespace App\Service\Video\Analyze;

use App\Entity\File;
use App\Entity\Video;
use App\Entity\VideoFace;
use App\Entity\VideoScene;
use App\Enum\VideoStatus;
use App\Service\Video\Analyze\Result\EmptyScenesMergerResult;
use App\Service\Video\Recording\RecordingGroups;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;

class EmptyScenesMerger
{
    /** @var array<int, File> */
    private array $orphanCandidates = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function mergeEmptyScenes(Video $video): EmptyScenesMergerResult
    {
        $originalCount = $video->getScenes()->count();

        if ($originalCount <= 1) {
            return EmptyScenesMergerResult::skipped('Not enough scenes to merge', $originalCount);
        }

        $this->orphanCandidates = [];

        return $this->entityManager->wrapInTransaction(function () use (
            $video,
            $originalCount,
        ): EmptyScenesMergerResult {
            if (!$this->lockAndVerifyStatus($video)) {
                return EmptyScenesMergerResult::skipped('Status conflict or concurrent processing', $originalCount);
            }

            // Nie über eine Aufnahmegrenze hinweg: jede Aufnahme (anderer Tag auf der Kassette) für sich
            $groups = RecordingGroups::scenes(
                array_values($video->getScenes()->toArray()),
                $video->getRecordingBoundaries(),
            );
            $allMerged = \count($groups) === 1;
            foreach ($groups as $sceneArray) {
                $scenesWithPerson = $this->extractScenesWithFaces($sceneArray);
                if (count($scenesWithPerson) === 0) {
                    $this->mergeAllScenesIntoOne($video, $sceneArray);
                } else {
                    $allMerged = false;
                    $this->mergeWithFaces($video, $sceneArray, $scenesWithPerson);
                }
            }
            $result = EmptyScenesMergerResult::success(
                $originalCount,
                $video->getScenes()->count(),
                fallbackAllMerged: $allMerged,
            );

            $this->renumberScenes($video);
            $this->entityManager->flush();

            $this->removeUnreferencedFiles();
            $this->entityManager->flush();

            return $result;
        });
    }

    /**
     * @param array<VideoScene> $scenes
     * @return array<VideoScene>
     */
    private function extractScenesWithFaces(array $scenes): array
    {
        $scenesWithPerson = [];
        foreach ($scenes as $scene) {
            if ($scene->getVideoFaces()->count() > 0) {
                $scenesWithPerson[] = $scene;
            }
        }

        return $scenesWithPerson;
    }

    /**
     * Sperrt die Video-Zeile innerhalb der laufenden Transaktion und prüft den Status.
     * Den Statuswechsel erledigt anschließend der Workflow, nicht dieser Service.
     */
    private function lockAndVerifyStatus(Video $video): bool
    {
        $connection = $this->entityManager->getConnection();
        // SQLite (Tests) kennt keine Zeilensperren und sperrt bei Schreibzugriffen ohnehin die ganze Datenbank
        $lock = $connection->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE';
        $currentStatus = $connection->fetchOne('SELECT status FROM video WHERE id = ?' . $lock, [$video->getId()]);

        return $currentStatus === VideoStatus::MERGING_SCENES->value;
    }

    /**
     * @param array<VideoScene> $scenes
     */
    private function mergeAllScenesIntoOne(Video $video, array $scenes): void
    {
        $firstScene = $scenes[0];
        $lastScene = $scenes[count($scenes) - 1];

        $firstScene->setEndSeconds($lastScene->getEndSeconds());

        $sceneCount = count($scenes);
        for ($i = 1; $i < $sceneCount; $i++) {
            $this->removeScene($video, $scenes[$i]);
        }
    }

    /**
     * @param array<VideoScene> $allScenes
     * @param array<VideoScene> $scenesWithPerson
     */
    private function mergeWithFaces(Video $video, array $allScenes, array $scenesWithPerson): void
    {
        $lastSceneWithPerson = null;
        $currentPersonSceneIndex = 0;
        $extendedNextScenes = [];

        foreach ($allScenes as $scene) {
            if ($scene->getVideoFaces()->count() > 0) {
                $lastSceneWithPerson = $scene;
                $currentPersonSceneIndex++;
                continue;
            }

            if ($lastSceneWithPerson !== null) {
                $lastSceneWithPerson->setEndSeconds($scene->getEndSeconds());
                $this->removeScene($video, $scene);
                continue;
            }

            if (array_key_exists($currentPersonSceneIndex, $scenesWithPerson)) {
                $nextSceneWithPerson = $scenesWithPerson[$currentPersonSceneIndex];
                $nextSceneKey = spl_object_id($nextSceneWithPerson);

                if (!array_key_exists($nextSceneKey, $extendedNextScenes)) {
                    $nextSceneWithPerson->setStartSeconds($scene->getStartSeconds());
                    $extendedNextScenes[$nextSceneKey] = true;
                }

                $this->removeScene($video, $scene);
            }
        }
    }

    private function removeScene(Video $video, VideoScene $scene): void
    {
        // Thumbnail entkoppeln, damit cascade "remove" keine ggf. geteilte File-Zeile mitlöscht.
        $thumbnail = $scene->getThumbnailFile();
        if ($thumbnail !== null) {
            $scene->setThumbnailFile(null);
            $this->orphanCandidates[spl_object_id($thumbnail)] = $thumbnail;
        }

        $video->removeScene($scene);
        $this->entityManager->remove($scene);
    }

    /**
     * Löscht File-Zeilen der entfernten Szenen nur, wenn nichts anderes sie referenziert.
     * Die physischen Dateien bleiben bewusst unberührt.
     */
    private function removeUnreferencedFiles(): void
    {
        foreach ($this->orphanCandidates as $file) {
            if ($file->getId() === null || $this->isFileReferenced($file)) {
                continue;
            }

            $this->entityManager->remove($file);
        }

        $this->orphanCandidates = [];
    }

    private function isFileReferenced(File $file): bool
    {
        $queries = [
            'SELECT COUNT(s.id) FROM ' . VideoScene::class . ' s WHERE s.thumbnailFile = :file',
            'SELECT COUNT(v.id) FROM '
                . Video::class
                . ' v
                WHERE v.thumbnailFile = :file OR v.convertedFile = :file OR v.sourceFile = :file',
            'SELECT COUNT(f.id) FROM ' . VideoFace::class . ' f WHERE f.faceImage = :file',
        ];

        foreach ($queries as $dql) {
            $count = (int) $this->entityManager
                ->createQuery($dql)
                ->setParameter('file', $file)
                ->getSingleScalarResult();

            if ($count > 0) {
                return true;
            }
        }

        return false;
    }

    private function renumberScenes(Video $video): void
    {
        $counter = 1;
        foreach ($video->getScenes() as $scene) {
            $scene->setSceneNumber($counter++);
        }
    }
}
