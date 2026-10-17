<?php

declare(strict_types=1);

namespace App\Service\Video\Analyze;

use App\Entity\Video;
use App\Service\Jellyfin\JellyfinUploadService;
use App\Service\Video\Analyze\Result\JellyfinExportResult;
use App\Service\VideoFileService;
use Doctrine\ORM\EntityManagerInterface;

class JellyfinUploader
{
    private const ITEM_LOOKUP_ATTEMPTS = 10;
    private const ITEM_LOOKUP_DELAY_SECONDS = 5;

    public function __construct(
        private readonly JellyfinUploadService $jellyfinUploadService,
        private readonly VideoFileService $videoFileService,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function exportVideo(Video $video): JellyfinExportResult
    {
        $resultPath = $video->getJellyfinPath();

        // Upload nur, wenn noch nicht geschehen (Retry soll nicht doppelt hochladen)
        if ($resultPath === null || $resultPath === '') {
            $file = $video->getConvertedFile() ?? $video->getSourceFile();
            if (!$file instanceof \App\Entity\File) {
                return new JellyfinExportResult(false, null, null, 'Video has no source file specified.');
            }

            $sourcePath = $this->videoFileService->getAbsolutePath($file->getRelativePath());
            if (!file_exists($sourcePath)) {
                return new JellyfinExportResult(
                    false,
                    null,
                    null,
                    sprintf('Local file "%s" does not exist.', $sourcePath),
                );
            }

            $filename = basename($sourcePath);
            if (preg_match('/\.mp4$/i', $filename)) {
                $filename = preg_replace('/\.[^.]+\.mp4$/i', '.mp4', $filename) ?? $filename;
            }

            $resultPath = $this->jellyfinUploadService->uploadVideo($sourcePath, $filename);
            if ($resultPath === false) {
                return new JellyfinExportResult(false, null, null, 'Failed to upload video to Jellyfin server.');
            }

            $video->setJellyfinPath($resultPath);
            $this->entityManager->flush();
        }

        $itemId = $video->getJellyfinItemId() ?? $this->waitForItemId($resultPath);

        if ($itemId && $video->getJellyfinItemId() === null) {
            $video->setJellyfinItemId($itemId);
            $this->entityManager->flush();
        }

        return new JellyfinExportResult(true, $resultPath, $itemId);
    }

    /**
     * Jellyfin scannt neue Dateien asynchron: sofort probieren, dann in Abständen erneut.
     */
    private function waitForItemId(string $resultPath): ?string
    {
        for ($i = 0; $i < self::ITEM_LOOKUP_ATTEMPTS; $i++) {
            $itemId = $this->jellyfinUploadService->findItemIdByPath($resultPath);
            if ($itemId) {
                return $itemId;
            }

            if ($i < (self::ITEM_LOOKUP_ATTEMPTS - 1)) {
                sleep(self::ITEM_LOOKUP_DELAY_SECONDS);
            }
        }

        return null;
    }
}
