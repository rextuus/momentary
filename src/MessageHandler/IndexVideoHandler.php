<?php

namespace App\MessageHandler;

use App\Message\IndexVideoMessage;
use App\Repository\VideoRepository;
use App\Service\VideoIndexer;
use Doctrine\ORM\EntityManagerInterface;
use Meilisearch\Client as MeiliSearchClient;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class IndexVideoHandler
{
    public function __construct(
        private VideoRepository $videoRepository,
        private VideoIndexer $videoIndexer,
        private MeiliSearchClient $meiliSearchClient,
        private EntityManagerInterface $entityManager
    ) {}

    public function __invoke(IndexVideoMessage $message): void
    {
        file_put_contents('var/log/handler.log', 'Handler called for video: ' . $message->getVideoId() . ' - ' . date('Y-m-d H:i:s') . PHP_EOL, FILE_APPEND);
        
        $this->videoRepository->getEntityManager()->clear();
        $video = $this->videoRepository->find($message->getVideoId());
        if (!$video) {
            return;
        }
        $this->entityManager->refresh($video);

        $data = $this->videoIndexer->transform($video);
        file_put_contents('var/log/handler_data.log', 'Transformed data: ' . json_encode($data) . PHP_EOL, FILE_APPEND);
        $this->meiliSearchClient->index('videos')->addDocuments([$data]);
    }
}
