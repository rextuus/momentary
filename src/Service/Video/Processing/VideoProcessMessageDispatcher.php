<?php

declare(strict_types=1);

namespace App\Service\Video\Processing;

use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

readonly class VideoProcessMessageDispatcher
{
    public function __construct(private MessageBusInterface $bus)
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
}
