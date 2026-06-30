<?php

namespace App\MessageHandler;

use App\Message\OptimizeVideoForJellyfinMessage;
use App\Message\ExportVideoToJellyfinMessage;
use App\Repository\VideoRepository;
use App\Service\VideoAnalyzer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Process\Process;

#[AsMessageHandler]
class OptimizeVideoForJellyfinMessageHandler
{
    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly VideoAnalyzer $videoAnalyzer,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%env(PYTHON_BINARY)%')] private readonly string $pythonBinary = '/usr/bin/python3'
    ) {
    }

    public function __invoke(OptimizeVideoForJellyfinMessage $message): void
    {
        $videoId = $message->getVideoId();
        $video = $this->videoRepository->find($videoId);

        if (!$video) {
            $this->logger->error("Could not find video $videoId for Jellyfin optimization.");
            return;
        }

        $localPath = $video->getLocalPath();
        if (!$localPath) {
            $this->logger->error("Video $videoId has no local path for optimization.");
            return;
        }

        $sourcePath = $this->videoAnalyzer->resolvePath($localPath);
        if (!file_exists($sourcePath)) {
            $this->logger->error("Local file $sourcePath for video $videoId does not exist.");
            return;
        }

        // If it's already an MP4, we can skip optimization or still run it for web-optimizing
        // For now, let's always optimize if requested, or skip if already mp4
        if (str_ends_with(strtolower($sourcePath), '.mp4')) {
            $this->logger->info("Video $videoId is already MP4, skipping optimization.");
            $this->messageBus->dispatch(new ExportVideoToJellyfinMessage($videoId));
            return;
        }

        $this->logger->info("Starting asynchronous optimization for video $videoId: $sourcePath");

        $outputPath = preg_replace('/\.[^.]+$/', '', $sourcePath) . '.mp4';
        
        // Ensure we don't overwrite the source if it happened to be named .mp4 but we are converting anyway
        if ($outputPath === $sourcePath) {
            $outputPath = $sourcePath . '.optimized.mp4';
        }
        
        // Call Python script for conversion
        $scriptPath = $this->projectDir . '/video-analyzer/python/convert_to_mp4.py';
        
        // Determine which python binary to use
        $pythonBinary = $this->pythonBinary;
        
        // Check if the configured binary is an absolute path and if it exists
        if (str_starts_with($pythonBinary, '/') && !file_exists($pythonBinary)) {
            $this->logger->info("Configured PYTHON_BINARY ($pythonBinary) not found, falling back to 'python3'");
            $pythonBinary = 'python3';
        }

        $process = new Process([$pythonBinary, $scriptPath, $sourcePath, $outputPath]);
        
        // Add environment variables if necessary (e.g. to ensure ffmpeg is in path)
        // Inside Docker, /usr/bin/python3 and ffmpeg should be available.
        // On Host, it depends on the user's setup.
        
        $process->setTimeout(3600);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->logger->error("Optimization failed for video $videoId (Command: {$process->getCommandLine()}): " . $process->getErrorOutput());
            return;
        }

        // The script outputs JSON at the end, but we also have the file
        if (file_exists($outputPath)) {
            $this->logger->info("Optimization successful for video $videoId. New path: $outputPath");
            
            // Update the local path to the new MP4 file
            // We make it relative to the uploads dir if possible
            $relativeOutputPath = $this->videoAnalyzer->makePathRelative($outputPath);
            $video->setLocalPath($relativeOutputPath);
            $this->entityManager->flush();

            // Now trigger the actual export
            $this->messageBus->dispatch(new ExportVideoToJellyfinMessage($videoId));

            // Optionally, we could delete the intermediate MP4 file if we wanted to keep the original only,
            // but since we updated localPath to it, we should keep it.
            // Actually, if it was an import, the original might be something we want to keep or replace.
            // The current logic replaces the reference in the database to point to the new .mp4.
        } else {
            $this->logger->error("Optimization script finished but output file $outputPath was not found.");
        }
    }
}
