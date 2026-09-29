<?php

declare(strict_types=1);

namespace App\Service\Video\Processing;

use App\Entity\Video;
use App\Service\Video\Processing\Message\ConvertStepMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

readonly class VideoProcessMessageDispatcher
{
    public function __construct(
        private MessageBusInterface $bus,
        private LoggerInterface $logger
    ) {
    }

    public function dispatch(VideoProcessStepMessageInterface $message): bool
    {
        try {
            $this->logger->info('VideoProcessMessageDispatcher: Dispatching message ' . get_class($message));
            $this->bus->dispatch($message);
        } catch (ExceptionInterface $e) {
            $this->logger->error('VideoProcessMessageDispatcher: Error dispatching message ' . $e->getMessage());
            return false;
        }

        return true;
    }

    public function dispatchInitialProcessMessage(Video $video): bool
    {
        return $this->dispatch(new ConvertStepMessage($video->getId(), 0, 'initial'));
    }
}
