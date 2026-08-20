<?php

declare(strict_types=1);

namespace App\Service\Video\Processing;

use App\Entity\Video;
use App\Service\Video\Processing\Message\ConvertStepMessage;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class VideoProcessMessageDispatcher
{
    public function __construct(private readonly MessageBusInterface $bus)
    {
    }

    public function dispatch(VideoProcessStepMessageInterface $message): bool
    {
        try {
            $this->bus->dispatch($message);
        } catch (ExceptionInterface $e) {
            return false;
        }

        return true;
    }

    public function dispatchInitialProcessMessage(Video $video): bool
    {
        return $this->dispatch(new ConvertStepMessage($video->getId(), 0, 'initial'));
    }
}
