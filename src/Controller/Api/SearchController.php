<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\Video;
use App\Entity\VideoScene;
use App\Repository\VideoRepository;
use App\Repository\VideoSceneRepository;
use App\Service\ImgproxyService;
use App\Service\VideoSearchIndex;
use Meilisearch\Client as MeiliSearchClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

final class SearchController extends AbstractController
{
    private const MAX_RESULTS = 20;

    public function __construct(
        private MeiliSearchClient $meiliSearchClient,
        private VideoRepository $videoRepository,
        private VideoSceneRepository $videoSceneRepository,
        private SerializerInterface $serializer,
        private ImgproxyService $imgproxyService,
    ) {}

    #[Route('/api/search', name: 'api_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->query->get('q', ''));
        $persons = $this->stringList($request->query->all('persons'));
        $tags = $this->stringList($request->query->all('tags'));

        // Filter: alle gewählten Personen und Tags müssen im Video vorkommen
        $filters = [];
        foreach ($persons as $person) {
            $filters[] = sprintf('persons = "%s"', $this->escapeFilterValue($person));
        }
        foreach ($tags as $tag) {
            $filters[] = sprintf('tags = "%s"', $this->escapeFilterValue($tag));
        }

        $options = ['limit' => self::MAX_RESULTS];
        if ($filters !== []) {
            $options['filter'] = implode(' AND ', $filters);
        }

        try {
            $hits = $this->meiliSearchClient
                ->index(VideoSearchIndex::INDEX_NAME)
                ->search($query === '' ? null : $query, $options)
                ->getHits();
        } catch (\Throwable $e) {
            return new JsonResponse([
                'error' => 'Suche nicht erreichbar: ' . $e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        if ($hits === []) {
            return new JsonResponse([]);
        }

        $videos = $this->videoRepository->findBy(['id' => array_map('intval', array_column($hits, 'id'))]);
        $videoMap = [];
        $scenesByVideo = [];
        $sceneByStart = [];
        foreach ($videos as $video) {
            $videoId = $video->getId();
            if ($videoId === null) {
                continue;
            }
            $videoMap[$videoId] = $video;
            $scenesByVideo[$videoId] = $this->videoSceneRepository->findBy(['video' => $video]);
            foreach ($scenesByVideo[$videoId] as $scene) {
                $sceneByStart[$videoId][(int) $scene->getStartSeconds()] = $scene;
            }
        }

        // In der Reihenfolge der Meilisearch-Treffer ausgeben
        $result = [];
        foreach ($hits as $hit) {
            $videoId = (int) $hit['id'];
            $video = $videoMap[$videoId] ?? null;
            if ($video === null) {
                continue; // veralteter Index-Eintrag
            }

            $blur = $this->isGranted('VIDEO_VIEW', $video) ? 0 : 5;

            $data = json_decode($this->serializer->serialize($video, 'json', ['groups' => ['video:list']]), true);
            $data['id'] = $video->getId();
            $data['scenes'] = json_decode($this->serializer->serialize(
                $scenesByVideo[$videoId] ?? [],
                'json',
                ['groups' => ['video:detail']],
            ), true);

            // Auch das Haupt-Thumbnail des Videos bei fehlender Berechtigung unscharf machen
            if (isset($data['thumbnailUrl'])) {
                $data['thumbnailUrl'] = $this->imgproxyService->generateUrl(
                    $data['thumbnailUrl'],
                    160,
                    90,
                    'fill',
                    $blur,
                );
            }

            $result[] = array_merge($data, [
                'persons_with_scenes' => $this->prepareSceneItems(
                    $hit['persons_with_scenes'] ?? [],
                    $persons,
                    $blur,
                    $sceneByStart[$videoId] ?? [],
                ),
                'tags_with_scenes' => $this->prepareSceneItems(
                    $hit['tags_with_scenes'] ?? [],
                    $tags,
                    $blur,
                    $sceneByStart[$videoId] ?? [],
                ),
                'blur' => $blur,
                'accessInfo' => $this->describeAccess($video),
                'availabilityStatus' => $video->isPublic() ? 'Öffentlich' : 'Privat',
            ]);
        }

        return new JsonResponse($result);
    }

    /**
     * Filtert Szenen-Einträge auf die angefragten Namen und erzeugt (ggf. unscharfe) Thumbnail-URLs.
     *
     * @param array<mixed>               $items          Szenen-Einträge aus dem Index-Dokument
     * @param list<string>               $requestedNames
     * @param array<int, VideoScene>     $sceneByStart   Szenen des Videos nach Startsekunde
     *
     * @return list<array<string, mixed>>
     */
    private function prepareSceneItems(array $items, array $requestedNames, int $blur, array $sceneByStart): array
    {
        if ($requestedNames !== []) {
            $items = array_filter($items, static fn($item) => in_array($item['name'] ?? null, $requestedNames, true));
        }

        foreach ($items as &$item) {
            if (!isset($item['thumbnailUrl'])) {
                continue;
            }

            $itemBlur = $blur;
            $scene = isset($item['start']) ? $sceneByStart[(int) $item['start']] ?? null : null;
            if ($scene !== null) {
                $itemBlur = $this->isGranted('SCENE_VIEW', $scene) ? 0 : 5;
                $item['endSeconds'] = $scene->getEndSeconds();
            }

            $item['thumbnailUrl'] = $this->imgproxyService->generateUrl(
                $item['thumbnailUrl'],
                160,
                90,
                'fill',
                $itemBlur,
            );
        }
        unset($item);

        return array_values($items);
    }

    private function describeAccess(Video $video): string
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return 'Sichtbar (Admin)';
        }

        if ($video->isPublic()) {
            return 'Öffentlich sichtbar';
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return 'Nicht sichtbar';
        }

        if ($video->getOwner() && $video->getOwner()->getId() === $user->getId()) {
            return 'Sichtbar (Eigentümer)';
        }

        foreach ($video->getAllowedGroups() as $group) {
            foreach ($group->getMembers() as $member) {
                if ($member->getUser() && $member->getUser()->getId() === $user->getId()) {
                    return 'Sichtbar (Mitglied in Gruppe ' . $group->getName() . ')';
                }
            }
        }

        return 'Nicht sichtbar';
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        return array_values(array_filter($values, 'is_string'));
    }

    /**
     * Meilisearch maskiert Anführungszeichen und Backslashes in Filterwerten mit einem Backslash.
     */
    private function escapeFilterValue(string $value): string
    {
        return addcslashes($value, '\\"');
    }
}
