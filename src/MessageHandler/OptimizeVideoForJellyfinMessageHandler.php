<?php

namespace App\MessageHandler;

use App\Message\OptimizeVideoForJellyfinMessage;
use App\Message\ExportVideoToJellyfinMessage;
use App\Repository\VideoRepository;
use App\Service\VideoAnalyzer;
use App\Service\WorkflowMachine;
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
        private readonly WorkflowMachine $workflowMachine,
        private readonly \App\Service\VideoProcessingService $processingService,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%env(PYTHON_BINARY)%')] private readonly string $pythonBinary = '/usr/bin/python3',
        #[Autowire('%env(bool:ENABLE_TAGGING_SCENES)%')] private readonly bool $enableTagging = true
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

        // Guard: Wenn das Video bereits weiter als OPTIMIZING ist, nichts tun (verhindert Re-Dispatch-Bugs)
        $alreadyPast = match($video->getStatus()) {
            \App\Enum\VideoStatus::TAGGING_SCENES,
            \App\Enum\VideoStatus::CHAPTER_GENERATION,
            \App\Enum\VideoStatus::COMPLETED => true,
            default => false,
        };
        if ($alreadyPast) {
            fwrite(STDOUT, "[OptimizeVideo] Video $videoId ist bereits in Status " . $video->getStatus()->value . " – ignoriere doppelten Dispatch." . PHP_EOL);
            return;
        }

        if ($this->workflowMachine->can($video, 'start_optimization')) {
            $this->workflowMachine->apply($video, 'start_optimization');
            $this->processingService->startStep($video, \App\Enum\VideoStatus::OPTIMIZING);
        }

        $localPath = $video->getLocalPath();
        if (!$localPath) {
            $this->logger->error("Video $videoId has no local path for optimization.");
            return;
        }

        $sourcePath = $this->videoAnalyzer->resolvePath($localPath);
        
        $this->logger->info("Resolved source path for optimization: $sourcePath (Original: $localPath)");

        if (!file_exists($sourcePath)) {
            // Eine kurze Pause einlegen und noch mal probieren, falls die Datei gerade erst geschrieben wurde (z.B. langsames NFS/Mount)
            clearstatcache(true, $sourcePath);
            if (!file_exists($sourcePath)) {
                sleep(1);
                clearstatcache(true, $sourcePath);
            }

            if (!file_exists($sourcePath)) {
                $this->logger->error("Local file $sourcePath for video $videoId does not exist. (Tried resolving from $localPath)");
                if ($this->workflowMachine->can($video, 'fail')) {
                    $this->workflowMachine->apply($video, 'fail');
                }
                $this->processingService->failStep($video, \App\Enum\VideoStatus::OPTIMIZING, "Source file not found: $sourcePath");
                $video->setErrorMessage("Source file not found: $sourcePath");
                $this->entityManager->flush();
                return;
            }
        }

        // If it's already an MP4, we can skip optimization or still run it for web-optimizing
        // For now, let's always optimize if requested, or skip if already mp4
        if (str_ends_with(strtolower($sourcePath), '.mp4')) {
            fwrite(STDOUT, "Video $videoId ist bereits MP4." . PHP_EOL);
            $this->logger->info("Video $videoId is already MP4, skipping optimization.");
            
            fwrite(STDOUT, "[OptimizeVideo] enableTagging={$this->enableTagging}, can(start_tagging)=" . ($this->workflowMachine->can($video, 'start_tagging') ? 'true' : 'false') . ", status=" . $video->getStatus()->value . PHP_EOL);
            if ($this->enableTagging && $this->workflowMachine->can($video, 'start_tagging')) {
                fwrite(STDOUT, "[OptimizeVideo] Dispatche TagScenesMessage für Video $videoId." . PHP_EOL);
                $this->workflowMachine->apply($video, 'start_tagging');
                $this->messageBus->dispatch(new \App\Message\TagScenesMessage($videoId));
            } elseif ($this->workflowMachine->can($video, 'complete')) {
                fwrite(STDOUT, "[OptimizeVideo] Tagging deaktiviert oder nicht möglich – dispatche ExportVideoToJellyfinMessage für Video $videoId." . PHP_EOL);
                $this->workflowMachine->apply($video, 'complete');
                $this->messageBus->dispatch(new ExportVideoToJellyfinMessage($videoId));
            } else {
                fwrite(STDOUT, "[OptimizeVideo] WARNUNG: Weder start_tagging noch complete möglich für Video $videoId (Status: " . $video->getStatus()->value . ")!" . PHP_EOL);
            }
            
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
            if ($this->workflowMachine->can($video, 'fail')) {
                $this->workflowMachine->apply($video, 'fail');
            }
            $video->setErrorMessage("Optimization failed: " . $process->getErrorOutput());
            $this->entityManager->flush();
            return;
        }

        // The script outputs JSON at the end, but we also have the file
        if (file_exists($outputPath)) {
            $this->logger->info("Optimization successful for video $videoId. New path: $outputPath");
            
            // Update the local path to the new MP4 file
            // We make it relative to the uploads dir if possible
            $relativeOutputPath = $this->videoAnalyzer->makePathRelative($outputPath);
            $video->setLocalPath($relativeOutputPath);
            
            $this->processingService->finishStep($video, \App\Enum\VideoStatus::OPTIMIZING);
            
            fwrite(STDOUT, "[OptimizeVideo] Nach Konvertierung: enableTagging={$this->enableTagging}, can(start_tagging)=" . ($this->workflowMachine->can($video, 'start_tagging') ? 'true' : 'false') . ", status=" . $video->getStatus()->value . PHP_EOL);
            if ($this->enableTagging && $this->workflowMachine->can($video, 'start_tagging')) {
                fwrite(STDOUT, "[OptimizeVideo] Dispatche TagScenesMessage für Video $videoId (nach Konvertierung)." . PHP_EOL);
                $this->workflowMachine->apply($video, 'start_tagging');
                $this->messageBus->dispatch(new \App\Message\TagScenesMessage($videoId));
            } elseif ($this->workflowMachine->can($video, 'complete')) {
                fwrite(STDOUT, "[OptimizeVideo] Tagging deaktiviert – dispatche ExportVideoToJellyfinMessage für Video $videoId." . PHP_EOL);
                $this->workflowMachine->apply($video, 'complete');
                $this->messageBus->dispatch(new ExportVideoToJellyfinMessage($videoId));
            } else {
                fwrite(STDOUT, "[OptimizeVideo] WARNUNG: Weder start_tagging noch complete möglich für Video $videoId (Status: " . $video->getStatus()->value . ")!" . PHP_EOL);
            }
            
            $this->entityManager->flush();

            // Optionally, we could delete the intermediate MP4 file if we wanted to keep the original only,
            // but since we updated localPath to it, we should keep it.
            // Actually, if it was an import, the original might be something we want to keep or replace.
            // The current logic replaces the reference in the database to point to the new .mp4.
        } else {
            $this->logger->error("Optimization script finished but output file $outputPath was not found.");
        }
    }
}
