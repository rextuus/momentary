<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Video;
use Meilisearch\Client as MeiliSearchClient;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Exceptions\ApiException;
use Psr\Log\LoggerInterface;

/**
 * Zentrale Stelle für den Meilisearch-Index "videos":
 * legt Index und Filter-Einstellungen bei Bedarf an, indexiert und entfernt Videos.
 */
class VideoSearchIndex
{
    public const INDEX_NAME = 'videos';
    private const FILTERABLE = ['tags', 'persons'];

    public function __construct(
        private readonly MeiliSearchClient $client,
        private readonly VideoIndexer $indexer,
        private readonly LoggerInterface $logger,
    ) {}

    public function ensureIndex(): Indexes
    {
        try {
            $index = $this->client->getIndex(self::INDEX_NAME);
        } catch (ApiException $e) {
            $task = $this->client->createIndex(self::INDEX_NAME, ['primaryKey' => 'id']);
            $this->client->waitForTask($task['taskUid']);
            $index = $this->client->getIndex(self::INDEX_NAME);
        }

        // Meilisearch kann Filter-Attribute auch als Objekte (granulare Einstellungen) liefern
        $current = $index->getFilterableAttributes();
        $missing = array_diff(self::FILTERABLE, array_filter($current, 'is_string'));
        if ($missing !== []) {
            $task = $index->updateFilterableAttributes([...$current, ...array_values($missing)]);
            $this->client->waitForTask($task['taskUid']);
        }

        return $index;
    }

    public function index(Video $video): void
    {
        $this->ensureIndex()->addDocuments([$this->indexer->transform($video)]);
    }

    /**
     * @param iterable<Video> $videos
     */
    public function indexMany(iterable $videos): int
    {
        $index = $this->ensureIndex();
        $batch = [];
        $count = 0;

        foreach ($videos as $video) {
            $batch[] = $this->indexer->transform($video);
            $count++;

            if (count($batch) >= 100) {
                $task = $index->addDocuments($batch);
                $this->client->waitForTask($task['taskUid']);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $task = $index->addDocuments($batch);
            $this->client->waitForTask($task['taskUid']);
        }

        return $count;
    }

    public function clear(): void
    {
        $task = $this->ensureIndex()->deleteAllDocuments();
        $this->client->waitForTask($task['taskUid']);
    }

    public function remove(int $videoId): void
    {
        $this->client->index(self::INDEX_NAME)->deleteDocument((string) $videoId);
    }

    /**
     * Welche Tags und Personen kommen im Index vor, und wie oft?
     *
     * @return array{tags: array<string, int>, persons: array<string, int>}
     */
    public function facets(): array
    {
        try {
            $result = $this->client->index(self::INDEX_NAME)->search('', [
                'facets' => ['tags', 'persons'],
                'limit' => 0,
            ]);
            $distribution = $result->getFacetDistribution();

            return [
                'tags' => $distribution['tags'] ?? [],
                'persons' => $distribution['persons'] ?? [],
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('VideoSearchIndex: Facetten nicht abrufbar: ' . $e->getMessage());

            return ['tags' => [], 'persons' => []];
        }
    }
}
