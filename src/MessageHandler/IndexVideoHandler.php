<?php

namespace App\MessageHandler;

use App\Message\IndexVideoMessage;
use App\Repository\VideoRepository;
use App\Service\VideoIndexer;
use Doctrine\ORM\EntityManagerInterface;
use Meilisearch\Client as MeiliSearchClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class IndexVideoHandler
{
    public function __construct(
        private VideoRepository $videoRepository,
        private VideoIndexer $videoIndexer,
        private MeiliSearchClient $meiliSearchClient,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {}

    public function __invoke(IndexVideoMessage $message): void
    {
        $videoId = $message->getVideoId();
        $this->logger->info("Starte Indexierung für Video $videoId");

        $this->videoRepository->getEntityManager()->clear();
        $video = $this->videoRepository->find($videoId);
        if (!$video) {
            $this->logger->error("Video $videoId nicht gefunden.");
            return;
        }
        $this->entityManager->refresh($video);

        try {
            $this->logger->info("Kompiliere Dokument für Meilisearch für Video $videoId...");
            $data = $this->videoIndexer->transform($video);
            
            $this->logger->info("Sende Daten an Meilisearch für Video $videoId...");
            $index = $this->meiliSearchClient->index('videos');
            $response = $index->addDocuments([$data]);
            
            $this->logger->info("Erfolgreich an Meilisearch gesendet: Video $videoId. Task ID: " . ($response['taskUid'] ?? 'N/A'));
        } catch (\Exception $e) {
            $this->logger->error("Fehler bei der Indexierung von Video $videoId: " . $e->getMessage());
            throw $e;
        }
    }
}
