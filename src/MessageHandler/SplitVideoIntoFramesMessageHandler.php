<?php

namespace App\MessageHandler;

use App\Message\SplitVideoIntoFramesMessage;
use App\Service\VideoAnalyzer;
use App\Service\WorkflowMachine;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SplitVideoIntoFramesMessageHandler
{
    public function __construct(
        private VideoAnalyzer $videoAnalyzer,
        private WorkflowMachine $workflowMachine
    ) {}

    public function __invoke(SplitVideoIntoFramesMessage $message): void
    {
        $video = $this->videoAnalyzer->getVideoRepository()->find($message->getVideoId());
        if ($video) {
            if ($this->workflowMachine->can($video, 'start_splitting')) {
                $this->workflowMachine->apply($video, 'start_splitting');
            }
            $video->setErrorMessage(null);
        }

        $localVideoPath = $this->videoAnalyzer->resolvePath($message->getLocalVideoPath());
        $this->videoAnalyzer->extractFrames($message->getVideoId(), $localVideoPath);
    }
}
