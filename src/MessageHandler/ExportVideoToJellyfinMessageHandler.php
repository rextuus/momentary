<?php

namespace App\MessageHandler;

use App\Message\ExportVideoToJellyfinMessage;
use App\Repository\VideoRepository;
use App\Service\JellyfinUploadService;
use App\Service\VideoAnalyzer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ExportVideoToJellyfinMessageHandler
{
    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly JellyfinUploadService $jellyfinUploadService,
        private readonly VideoAnalyzer $videoAnalyzer,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(ExportVideoToJellyfinMessage $message): void
    {
        $videoId = $message->getVideoId();
        $video = $this->videoRepository->find($videoId);

        if (!$video) {
            $this->logger->error("Could not find video $videoId for Jellyfin export.");
            return;
        }

        $localPath = $video->getLocalPath();
        if (!$localPath) {
            $this->logger->error("Video $videoId has no local path for Jellyfin export.");
            return;
        }

        $sourcePath = $this->videoAnalyzer->resolvePath($localPath);
        if (!file_exists($sourcePath)) {
            $this->logger->error("Local file $sourcePath for video $videoId does not exist.");
            return;
        }

        $this->logger->info("Processing asynchronous Jellyfin export for video $videoId");
        
        $filename = basename($sourcePath);
        
        // Clean up filename if it's an optimized video (e.g. sample.mpg.mp4 -> sample.mp4)
        // But since we fixed the optimization handler, it should already be something.mp4
        // However, if it's still double extension, we clean it.
        if (preg_match('/\.mp4$/i', $filename)) {
            // Replace double extensions like .mpg.mp4 with .mp4
            $filename = preg_replace('/\.[^.]+\.mp4$/i', '.mp4', $filename);
        }
        
        $result = $this->jellyfinUploadService->uploadVideo($sourcePath, $filename);

        if ($result) {
            $video->setJellyfinPath($result);
            $this->entityManager->flush();
            $this->logger->info("Successfully exported video $videoId to Jellyfin: $result");

            // We try to find the ItemID immediately, but it might take a moment until the scan is done
            // We use a simple retry loop
            $itemId = null;
            for ($i = 0; $i < 5; $i++) {
                $this->logger->info("Searching for Jellyfin Item ID (Attempt " . ($i + 1) . "/5)...");
                sleep(3); // Wait for Jellyfin to index
                $itemId = $this->jellyfinUploadService->findItemIdByPath($result);
                if ($itemId) {
                    break;
                }
            }

            if ($itemId) {
                $video->setJellyfinItemId($itemId);
                $this->entityManager->flush();
                $this->logger->info("Associated Jellyfin Item ID $itemId with video $videoId");
            } else {
                $this->logger->warning("Could not find Jellyfin Item ID for video $videoId after several attempts.");
            }
        } else {
            $this->logger->error("Failed to export video $videoId to Jellyfin.");
        }
    }
}
