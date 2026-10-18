<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\Medium;
use App\Entity\Photo;
use App\Entity\User;
use App\Entity\Video;
use App\Repository\MediumRepository;
use App\Service\Photo\PhotoSearchIndex;
use App\Service\VideoSearchIndex;
use Doctrine\ORM\EntityManagerInterface;
use Meilisearch\Client;
use Meilisearch\Contracts\SearchQuery;
use Psr\Log\LoggerInterface;

/**
 * Gemeinsame Suche über Videos und Fotos (eine Multi-Search-Anfrage an beide Meilisearch-Indizes).
 *
 * Anders als die externe Video-API werden Treffer, die der Benutzer nicht sehen darf, weggelassen statt unscharf
 * gezeigt. Zu Videos werden die Fundstellen (gesprochene Sätze, Szenen mit Person/Tag) mit Sekunde geliefert.
 */
class MediaSearch
{
    private const int VIDEO_LIMIT = 60;
    private const int PHOTO_LIMIT = 200;
    private const int MOMENTS_PER_VIDEO = 5;

    public function __construct(
        private readonly Client $client,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param list<string> $persons alle müssen vorkommen
     * @param list<string> $tags    alle müssen vorkommen
     */
    public function search(string $query, array $persons, array $tags, ?User $user, bool $isAdmin): MediaSearchResult
    {
        $query = trim($query);
        $filter = array_merge(
            array_map(static fn(string $p): string => sprintf('persons = "%s"', addcslashes($p, '\\"')), $persons),
            array_map(static fn(string $t): string => sprintf('tags = "%s"', addcslashes($t, '\\"')), $tags),
        );

        $queries = [];
        foreach ([
            VideoSearchIndex::INDEX_NAME => self::VIDEO_LIMIT,
            PhotoSearchIndex::INDEX_NAME => self::PHOTO_LIMIT,
        ] as $index => $limit) {
            $searchQuery = new SearchQuery()
                ->setIndexUid($index)
                ->setQuery($query)
                ->setLimit($limit);
            if ($filter !== []) {
                $searchQuery->setFilter($filter);
            }
            $queries[] = $searchQuery;
        }

        try {
            $response = $this->client->multiSearch($queries);
        } catch (\Throwable $e) {
            $this->logger->warning('Mediensuche: ' . $e->getMessage());

            return new MediaSearchResult(error: 'Die Suche ist gerade nicht erreichbar.');
        }

        $results = [];
        foreach ($response['results'] ?? [] as $result) {
            $results[(string) ($result['indexUid'] ?? '')] = $result;
        }
        $videoHits = $results[VideoSearchIndex::INDEX_NAME]['hits'] ?? [];
        $photoHits = $results[PhotoSearchIndex::INDEX_NAME]['hits'] ?? [];
        $terms = self::terms($query);

        // Facetten nur aus sichtbaren Treffern zählen: sonst verrieten sie Namen aus fremden privaten Medien
        $personCounts = [];
        $tagCounts = [];
        $count = static function (array $hit) use (&$personCounts, &$tagCounts): void {
            foreach (['persons' => &$personCounts, 'tags' => &$tagCounts] as $field => &$counts) {
                foreach (array_unique((array) ($hit[$field] ?? [])) as $name) {
                    $counts[(string) $name] = ($counts[(string) $name] ?? 0) + 1;
                }
            }
        };

        $videos = [];
        $visibleVideos = $this->visible(Video::class, $videoHits, $user, $isAdmin);
        foreach ($videoHits as $hit) {
            $video = $visibleVideos[(int) $hit['id']] ?? null;
            if ($video instanceof Video) {
                $videos[] = new VideoSearchHit($video, self::moments($hit, $terms, $persons, $tags));
                $count($hit);
            }
        }

        $photos = [];
        $visiblePhotos = $this->visible(Photo::class, $photoHits, $user, $isAdmin);
        foreach ($photoHits as $hit) {
            $photo = $visiblePhotos[(int) $hit['id']] ?? null;
            if ($photo instanceof Photo) {
                $photos[] = $photo;
                $count($hit);
            }
        }
        arsort($personCounts);
        arsort($tagCounts);

        return new MediaSearchResult($videos, $photos, $personCounts, $tagCounts, $terms);
    }

    /**
     * Suchbegriffe ab drei Zeichen, kleingeschrieben.
     *
     * @return list<string>
     */
    public static function terms(string $query): array
    {
        $words = preg_split('/[\s,.;:!?"]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($words, static fn(string $w): bool => mb_strlen($w) >= 3)));
    }

    /**
     * Fundstellen aus dem Index-Dokument eines Videos.
     *
     * @param array<string, mixed> $hit
     * @param list<string>         $terms
     * @param list<string>         $persons
     * @param list<string>         $tags
     *
     * @return list<SearchMoment>
     */
    public static function moments(array $hit, array $terms, array $persons, array $tags): array
    {
        $matches = static function (string $text) use ($terms): bool {
            $text = mb_strtolower($text);
            foreach ($terms as $term) {
                if (str_contains($text, $term)) {
                    return true;
                }
            }

            return false;
        };

        $moments = [];
        foreach ((array) ($hit['transcript'] ?? []) as $segment) {
            if (\is_array($segment) && $terms !== [] && $matches((string) ($segment['text'] ?? ''))) {
                $moments[] = new SearchMoment(
                    SearchMoment::SPOKEN,
                    (int) ($segment['start'] ?? 0),
                    (string) $segment['text'],
                );
            }
        }
        foreach ([
            'persons_with_scenes' => [SearchMoment::PERSON, $persons],
            'tags_with_scenes' => [SearchMoment::TAG, $tags],
        ] as $field => [$kind, $selected]) {
            foreach ((array) ($hit[$field] ?? []) as $scene) {
                if (!\is_array($scene)) {
                    continue;
                }
                $name = (string) ($scene['name'] ?? '');
                if (\in_array($name, $selected, true) || $terms !== [] && $matches($name)) {
                    $moments[] = new SearchMoment(
                        $kind,
                        (int) ($scene['start'] ?? 0),
                        $name,
                        isset($scene['thumbnailUrl']) ? (string) $scene['thumbnailUrl'] : null,
                    );
                }
            }
        }

        usort($moments, static fn(SearchMoment $a, SearchMoment $b): int => $a->seconds <=> $b->seconds);

        // Mehrere Tags bzw. Personen derselben Szene in einer Zeile ("Pool, Swimmingpool, Poolreiniger")
        $merged = [];
        foreach ($moments as $moment) {
            $key = $moment->kind === SearchMoment::SPOKEN
                ? spl_object_id($moment)
                : $moment->kind . '@' . $moment->seconds;
            if (isset($merged[$key]) && !str_contains(', ' . $merged[$key]->text . ', ', ', ' . $moment->text . ', ')) {
                $merged[$key] = new SearchMoment(
                    $moment->kind,
                    $moment->seconds,
                    $merged[$key]->text . ', ' . $moment->text,
                    $merged[$key]->thumbnailPath ?? $moment->thumbnailPath,
                );
            } else {
                $merged[$key] ??= $moment;
            }
        }

        return \array_slice(array_values($merged), 0, self::MOMENTS_PER_VIDEO);
    }

    /**
     * Medien zu den Treffern laden, die der Benutzer sehen darf.
     *
     * @template T of Medium
     *
     * @param class-string<T>            $class
     * @param list<array<string, mixed>> $hits
     *
     * @return array<int, T>
     */
    private function visible(string $class, array $hits, ?User $user, bool $isAdmin): array
    {
        $ids = array_map(static fn(array $hit): int => (int) $hit['id'], $hits);
        if ($ids === []) {
            return [];
        }

        $qb = $this->entityManager
            ->createQueryBuilder()
            ->select('m')
            ->from($class, 'm')
            ->where('m.id IN (:ids)')
            ->setParameter('ids', $ids);
        MediumRepository::restrictToVisible($qb, 'm', $user, $isAdmin);

        $byId = [];
        /** @var T $medium */
        foreach ($qb->getQuery()->getResult() as $medium) {
            $byId[(int) $medium->getId()] = $medium;
        }

        return $byId;
    }
}
