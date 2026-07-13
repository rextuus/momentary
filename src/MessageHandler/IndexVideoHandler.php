<?php

namespace App\MessageHandler;

use App\Message\IndexVideoMessage;
use App\Repository\VideoRepository;
use App\Service\VideoIndexer;
use Meilisearch\Client as MeiliSearchClient;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class IndexVideoHandler
{
    public function __construct(
        private VideoRepository $videoRepository,
        private VideoIndexer $videoIndexer,
        private MeiliSearchClient $meiliSearchClient
    ) {}

    public function __invoke(IndexVideoMessage $message): void
    {
        $video = $this->videoRepository->find($message->getVideoId());
        if (!$video) {
            return;
        }

        $data = $this->videoIndexer->transform($video);
        $this->meiliSearchClient->index('videos')->addDocuments([$data]);
    }
}
