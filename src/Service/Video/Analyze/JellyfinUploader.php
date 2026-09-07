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
    public function __construct(
        private readonly JellyfinUploadService $jellyfinUploadService,
        private readonly VideoFileService $videoFileService,
        private readonly EntityManagerInterface $entityManager
    ) {}

    public function exportVideo(Video $video): JellyfinExportResult
    {
        $file = $video->getConvertedFile() ?? $video->getSourceFile();
        if (!$file instanceof \App\Entity\File) {
            return new JellyfinExportResult(false, null, null, 'Video has no source file specified.');
        }

        $sourcePath = $this->videoFileService->getAbsolutePath($file->getRelativePath());
        if (!file_exists($sourcePath)) {
            return new JellyfinExportResult(false, null, null, sprintf('Local file "%s" does not exist.', $sourcePath));
        }

        $filename = basename($sourcePath);
        if (preg_match('/\.mp4$/i', $filename)) {
            $filename = preg_replace('/\.[^.]+\.mp4$/i', '.mp4', $filename);
        }

        $resultPath = $this->jellyfinUploadService->uploadVideo($sourcePath, $filename);
        if (!$resultPath) {
            return new JellyfinExportResult(false, null, null, 'Failed to upload video to Jellyfin server.');
        }

        $video->setJellyfinPath($resultPath);
        $this->entityManager->flush();

        // Attempting to find the Item ID with retries
        $itemId = null;
        for ($i = 0; $i < 5; $i++) {
            sleep(3);
            $itemId = $this->jellyfinUploadService->findItemIdByPath($resultPath);
            if ($itemId) {
                break;
            }
        }

        if ($itemId) {
            $video->setJellyfinItemId($itemId);
            $this->entityManager->flush();
        }

        return new JellyfinExportResult(true, $resultPath, $itemId);
    }
}