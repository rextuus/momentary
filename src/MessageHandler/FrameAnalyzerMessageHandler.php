<?php

namespace App\MessageHandler;

use App\Message\FrameAnalyzerMessage;
use App\Service\VideoAnalyzer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
readonly final class FrameAnalyzerMessageHandler
{
    public function __construct(
        private VideoAnalyzer $videoAnalyzer,
        private MessageBusInterface $messageBus,
    ) {}

    public function __invoke(FrameAnalyzerMessage $message): void
    {
        $framePath = $this->videoAnalyzer->resolvePath($message->getFramePath());

        $this->videoAnalyzer->analyzeFrame(
            $message->getVideoId(),
            $framePath,
            $message->getTimestamp(),
            $message->isLast(),
            $message->isRefinement()
        );

        // Chain: nächsten Frame dispatchen
        $remaining = $message->getRemainingFrames();
        if (!empty($remaining)) {
            $next = array_shift($remaining);
            $this->messageBus->dispatch(new FrameAnalyzerMessage(
                $message->getVideoId(),
                $next['path'],
                $next['timestamp'],
                $next['isLast'],
                $message->isRefinement(),
                $remaining
            ));
        }
    }
}