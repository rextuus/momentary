<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\EncodeWebVersionMessage;
use App\Repository\VideoRepository;
use App\Service\Video\WebVersion\WebVersionEncoder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class EncodeWebVersionMessageHandler
{
    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly WebVersionEncoder $encoder,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(EncodeWebVersionMessage $message): void
    {
        $video = $this->videoRepository->find($message->videoId);
        if ($video === null) {
            return;
        }
        $this->logger->info(sprintf(
            'Video %d: %s',
            $message->videoId,
            $this->encoder->encode($video, $message->force),
        ));
    }
}
