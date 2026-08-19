<?php

declare(strict_types=1);

namespace App\Service\Video\Analyze;

use App\Entity\Video;
use App\Entity\VideoScene;
use App\Enum\VideoStatus;
use App\Service\Video\Analyze\Result\EmptyScenesMergerResult;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

class EmptyScenesMerger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function mergeEmptyScenes(Video $video): EmptyScenesMergerResult
    {
        $scenes = $video->getScenes();
        $originalCount = $scenes->count();

        if ($originalCount <= 1) {
            return EmptyScenesMergerResult::skipped('Not enough scenes to merge', $originalCount);
        }

        if (!$this->lockAndVerifyStatus($video)) {
            return EmptyScenesMergerResult::skipped('Status conflict or concurrent processing', $originalCount);
        }

        try {
            /** @var array<VideoScene> $sceneArray */
            $sceneArray = $scenes->toArray();
            $scenesWithPerson = $this->extractScenesWithFaces($sceneArray);

            if (count($scenesWithPerson) === 0) {
                $result = $this->mergeAllScenesIntoOne($video, $sceneArray, $originalCount);
            } else {
                $result = $this->mergeWithFaces($video, $sceneArray, $scenesWithPerson, $originalCount);
            }

            $this->renumberScenes($video);
            $this->entityManager->flush();

            return $result;
        } catch (Throwable $e) {
            $this->entityManager->rollback();
            throw $e;
        }
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

    private function lockAndVerifyStatus(Video $video): bool
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        $currentStatus = $connection->fetchOne(
            'SELECT status FROM video WHERE id = ? FOR UPDATE',
            [$video->getId()]
        );

        if ($currentStatus !== VideoStatus::MERGING_SCENES->value) {
            $connection->rollBack();
            return false;
        }

        $connection->executeStatement(
            'UPDATE video SET status = ? WHERE id = ? AND status = ?',
            [VideoStatus::TAGGING_SCENES->value, $video->getId(), VideoStatus::MERGING_SCENES->value]
        );

        $connection->commit();
        return true;
    }

    /**
     * @param array<VideoScene> $scenes
     */
    private function mergeAllScenesIntoOne(Video $video, array $scenes, int $originalCount): EmptyScenesMergerResult
    {
        $firstScene = $scenes[0];
        $lastScene = end($scenes);

        $firstScene->setEndSeconds($lastScene->getEndSeconds());

        $sceneCount = count($scenes);
        for ($i = 1; $i < $sceneCount; $i++) {
            $this->removeScene($video, $scenes[$i]);
        }

        return EmptyScenesMergerResult::success($originalCount, 1, fallbackAllMerged: true);
    }

    /**
     * @param array<VideoScene> $allScenes
     * @param array<VideoScene> $scenesWithPerson
     */
    private function mergeWithFaces(
        Video $video,
        array $allScenes,
        array $scenesWithPerson,
        int $originalCount
    ): EmptyScenesMergerResult {
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
                $nextSceneId = $nextSceneWithPerson->getId();

                if (!array_key_exists($nextSceneId, $extendedNextScenes)) {
                    $nextSceneWithPerson->setStartSeconds($scene->getStartSeconds());
                    $extendedNextScenes[$nextSceneId] = true;
                }

                $this->removeScene($video, $scene);
            }
        }

        return EmptyScenesMergerResult::success(
            $originalCount,
            $video->getScenes()->count(),
            fallbackAllMerged: false
        );
    }

    private function removeScene(Video $video, VideoScene $scene): void
    {
        $video->removeScene($scene);
        $this->entityManager->remove($scene);
    }

    private function renumberScenes(Video $video): void
    {
        $counter = 1;
        foreach ($video->getScenes() as $scene) {
            $scene->setSceneNumber($counter++);
        }
    }
}