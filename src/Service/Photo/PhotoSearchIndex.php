<?php

declare(strict_types=1);

namespace App\Service\Photo;

use App\Entity\Photo;
use Meilisearch\Client as MeiliSearchClient;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Exceptions\ApiException;

/**
 * Meilisearch-Index "photos". Bewusst getrennt vom Index "videos": dessen Such-API nutzen externe Clients.
 */
class PhotoSearchIndex
{
    public const string INDEX_NAME = 'photos';
    private const array FILTERABLE = ['tags', 'persons', 'takenAt'];

    public function __construct(
        private readonly MeiliSearchClient $client,
    ) {}

    public function index(Photo $photo): void
    {
        $this->ensureIndex()->addDocuments([self::transform($photo)]);
    }

    public function remove(int $photoId): void
    {
        $this->client->index(self::INDEX_NAME)->deleteDocument((string) $photoId);
    }

    /**
     * @return array<string, mixed>
     */
    public static function transform(Photo $photo): array
    {
        $persons = [];
        foreach ($photo->getFaces() as $face) {
            $person = $face->getPerson();
            if ($person !== null && !$person->hasPlaceholderName()) {
                $persons[(string) $person->getName()] = true;
            }
        }

        $document = [
            'id' => (string) $photo->getId(),
            'title' => $photo->getTitle(),
            'takenAt' => $photo->getDate()?->getTimestamp(),
            'persons' => array_keys($persons),
            'tags' => array_values(array_unique(array_filter(array_map(
                static fn($tag): ?string => $tag->getName(),
                $photo->getTags()->toArray(),
            )))),
            'camera' => $photo->getCamera(),
        ];
        if ($photo->hasLocation()) {
            $document['_geo'] = ['lat' => $photo->getLatitude(), 'lng' => $photo->getLongitude()];
        }

        return $document;
    }

    private function ensureIndex(): Indexes
    {
        try {
            $index = $this->client->getIndex(self::INDEX_NAME);
        } catch (ApiException) {
            $task = $this->client->createIndex(self::INDEX_NAME, ['primaryKey' => 'id']);
            $this->client->waitForTask($task['taskUid']);
            $index = $this->client->getIndex(self::INDEX_NAME);
        }

        $current = array_filter($index->getFilterableAttributes(), 'is_string');
        $missing = array_diff(self::FILTERABLE, $current);
        if ($missing !== []) {
            $task = $index->updateFilterableAttributes([...$current, ...array_values($missing)]);
            $this->client->waitForTask($task['taskUid']);
        }

        return $index;
    }
}
