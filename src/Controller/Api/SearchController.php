<?php

namespace App\Controller\Api;

use App\Repository\VideoRepository;
use App\Service\ImgproxyService;
use Meilisearch\Client as MeiliSearchClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

class SearchController extends AbstractController
{
    public function __construct(
        private MeiliSearchClient $meiliSearchClient,
        private VideoRepository $videoRepository,
        private SerializerInterface $serializer,
        private ImgproxyService $imgproxyService
    ) {}

    #[Route('/api/search', name: 'api_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $query = $request->query->get('q', '');
        $persons = $request->query->all('persons');
        $tags = $request->query->all('tags');
        
        // Build filter
        $filters = [];
        foreach ($persons as $person) {
            $filters[] = sprintf("persons = '%s'", str_replace("'", "''", $person));
        }
        foreach ($tags as $tag) {
            $filters[] = sprintf("tags = '%s'", str_replace("'", "''", $tag));
        }
        
        $filterString = implode(' AND ', $filters);

        $options = [];
        if (!empty($filterString)) {
            $options['filter'] = $filterString;
        }

        $searchResult = $this->meiliSearchClient->index('videos')->search(empty($query) ? null : $query, $options);
        $hits = $searchResult->getHits();
        $videoIds = array_map('intval', array_column($hits, 'id'));

        if (empty($videoIds)) {
            return new JsonResponse([]);
        }

        $videos = $this->videoRepository->findBy(['id' => $videoIds]);

        // Videos nach ID mappen
        $videoMap = [];
        foreach ($videos as $video) {
            $videoMap[$video->getId()] = $video;
        }

        // MeiliSearch-Hits nach ID mappen
        $hitDataMap = [];
        foreach ($hits as $hit) {
            $video = $videoMap[(int)$hit['id']] ?? null;
            $blur = ($video && $this->isGranted('VIDEO_VIEW', $video)) ? 0 : 5;

            $personsWithScenes = $hit['persons_with_scenes'] ?? [];
            $tagsWithScenes = $hit['tags_with_scenes'] ?? [];

            // Filter persons_with_scenes if persons requested
            if (!empty($persons)) {
                $personsWithScenes = array_filter($personsWithScenes, function ($item) use ($persons) {
                    return in_array($item['name'], $persons);
                });
            }

            // Transform thumbnailUrl in personsWithScenes
            foreach ($personsWithScenes as &$item) {
                if (isset($item['thumbnailUrl'])) {
                    $item['thumbnailUrl'] = $this->imgproxyService->generateUrl($item['thumbnailUrl'], 320, 180, 'fill', $blur);
                }
            }
            unset($item);

            // Filter tags_with_scenes if tags requested
            if (!empty($tags)) {
                $tagsWithScenes = array_filter($tagsWithScenes, function ($item) use ($tags) {
                    return in_array($item['name'], $tags);
                });
            }

            // Transform thumbnailUrl in tagsWithScenes
            foreach ($tagsWithScenes as &$item) {
                if (isset($item['thumbnailUrl'])) {
                    $item['thumbnailUrl'] = $this->imgproxyService->generateUrl($item['thumbnailUrl'], 320, 180, 'fill', $blur);
                }
            }
            unset($item);

            $hitDataMap[(int)$hit['id']] = [
                'persons_with_scenes' => array_values($personsWithScenes),
                'tags_with_scenes' => array_values($tagsWithScenes),
                'blur' => $blur,
            ];
        }

        $result = [];
        foreach ($videos as $video) {
            $data = json_decode($this->serializer->serialize($video, 'json', ['groups' => ['video:list']]), true);
            $hitData = $hitDataMap[$video->getId()] ?? ['persons_with_scenes' => [], 'tags_with_scenes' => [], 'blur' => 5];
            
            $blur = $hitData['blur'] ?? 5;
            
            // Auch das Haupt-Thumbnail des Videos bluren
            if (isset($data['thumbnailUrl'])) {
                $data['thumbnailUrl'] = $this->imgproxyService->generateUrl($data['thumbnailUrl'], 320, 180, 'fill', $blur);
            }
            
            unset($hitData['blur']);
            
            $result[] = array_merge($data, $hitData);
        }

        return new JsonResponse($result);
    }
}
