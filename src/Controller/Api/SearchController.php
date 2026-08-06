<?php

namespace App\Controller\Api;

use App\Repository\VideoRepository;
use App\Repository\VideoSceneRepository;
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
        private VideoSceneRepository $videoSceneRepository,
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
        
        $sceneIds = [];
        foreach ($hits as $hit) {
            foreach ($hit['persons_with_scenes'] ?? [] as $item) {
                if (isset($item['id'])) $sceneIds[] = (int)$item['id'];
            }
            foreach ($hit['tags_with_scenes'] ?? [] as $item) {
                if (isset($item['id'])) $sceneIds[] = (int)$item['id'];
            }
        }
        $sceneMap = [];
        if (!empty($sceneIds)) {
            $scenes = $this->videoSceneRepository->findBy(['id' => array_unique($sceneIds)]);
            foreach ($scenes as $scene) {
                $sceneMap[$scene->getId()] = $scene;
            }
        }

        if (empty($videoIds)) {
            return new JsonResponse([]);
        }

        $videos = $this->videoRepository->findBy(['id' => $videoIds]);

        // Videos nach ID mappen
        $videoMap = [];
        foreach ($videos as $video) {
            $videoMap[$video->getId()] = $video;
        }

        // Build a map of scenes per video indexed by startSeconds for persons_with_scenes/tags_with_scenes lookup
        $sceneByVideoAndStart = [];
        foreach ($videos as $videoEntity) {
            $videoScenes = $this->videoSceneRepository->findBy(['video' => $videoEntity]);
            foreach ($videoScenes as $scene) {
                $sceneByVideoAndStart[$videoEntity->getId()][(int)$scene->getStartSeconds()] = $scene;
            }
        }

        // MeiliSearch-Hits nach ID mappen
        $hitDataMap = [];
        foreach ($hits as $hit) {
            $video = $videoMap[(int)$hit['id']] ?? null;
            $blur = ($video && $this->isGranted('VIDEO_VIEW', $video)) ? 0 : 5;
            
            $accessInfo = 'Nicht sichtbar';
            if ($this->isGranted('ROLE_ADMIN')) {
                $accessInfo = 'Sichtbar (Admin)';
            } elseif ($video && $video->isPublic()) {
                $accessInfo = 'Öffentlich sichtbar';
            } elseif ($video && $video->getOwner() && $video->getOwner()->getId() === $this->getUser()?->getId()) {
                $accessInfo = 'Sichtbar (Eigentümer)';
            } elseif ($video) {
                $user = $this->getUser();
                if ($user instanceof \App\Entity\User) {
                    foreach ($video->getAllowedGroups() as $group) {
                        foreach ($group->getMembers() as $member) {
                            if ($member->getUser() && $member->getUser()->getId() === $user->getId()) {
                                $accessInfo = 'Sichtbar (Mitglied in Gruppe ' . $group->getName() . ')';
                                break 2;
                            }
                        }
                    }
                }
            }

            $availabilityStatus = $video ? ($video->isPublic() ? 'Öffentlich' : 'Privat') : 'N/A';

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
                    $itemBlur = $blur;
                    if (isset($item['id']) && isset($sceneMap[$item['id']])) {
                        $itemBlur = $this->isGranted('SCENE_VIEW', $sceneMap[$item['id']]) ? 0 : 5;
                        $item['endSeconds'] = $sceneMap[$item['id']]->getEndSeconds();
                    } elseif (isset($item['start']) && isset($sceneByVideoAndStart[(int)$hit['id']][(int)$item['start']])) {
                        $itemBlur = $this->isGranted('SCENE_VIEW', $sceneByVideoAndStart[(int)$hit['id']][(int)$item['start']]) ? 0 : 5;
                        $item['endSeconds'] = $sceneByVideoAndStart[(int)$hit['id']][(int)$item['start']]->getEndSeconds();
                    }
                    $item['thumbnailUrl'] = $this->imgproxyService->generateUrl($item['thumbnailUrl'], 160, 90, 'fill', $itemBlur);
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
                    $itemBlur = $blur;
                    if (isset($item['id']) && isset($sceneMap[$item['id']])) {
                        $itemBlur = $this->isGranted('SCENE_VIEW', $sceneMap[$item['id']]) ? 0 : 5;
                        $item['endSeconds'] = $sceneMap[$item['id']]->getEndSeconds();
                    } elseif (isset($item['start']) && isset($sceneByVideoAndStart[(int)$hit['id']][(int)$item['start']])) {
                        $itemBlur = $this->isGranted('SCENE_VIEW', $sceneByVideoAndStart[(int)$hit['id']][(int)$item['start']]) ? 0 : 5;
                        $item['endSeconds'] = $sceneByVideoAndStart[(int)$hit['id']][(int)$item['start']]->getEndSeconds();
                    }
                    $item['thumbnailUrl'] = $this->imgproxyService->generateUrl($item['thumbnailUrl'], 160, 90, 'fill', $itemBlur);
                }
            }
            unset($item);

            $hitDataMap[(int)$hit['id']] = [
                'persons_with_scenes' => array_values($personsWithScenes),
                'tags_with_scenes' => array_values($tagsWithScenes),
                'blur' => $blur,
                'accessInfo' => $accessInfo,
                'availabilityStatus' => $availabilityStatus,
            ];
        }

        $result = [];
        foreach ($videos as $video) {
            $data = json_decode($this->serializer->serialize($video, 'json', ['groups' => ['video:list']]), true);
            $hitData = $hitDataMap[$video->getId()] ?? ['persons_with_scenes' => [], 'tags_with_scenes' => [], 'blur' => 5, 'accessInfo' => 'Nicht sichtbar', 'availabilityStatus' => 'N/A'];
            
            // $scenes = $video->getScenes();
            $scenes = $this->videoSceneRepository->findBy(['video' => $video]);
            $data['scenes'] = json_decode($this->serializer->serialize($scenes, 'json', ['groups' => ['video:detail']]), true);
            
            $blur = $hitData['blur'] ?? 5;
            
            // Auch das Haupt-Thumbnail des Videos bluren
            if (isset($data['thumbnailUrl'])) {
                $data['thumbnailUrl'] = $this->imgproxyService->generateUrl($data['thumbnailUrl'], 160, 90, 'fill', $blur);
            }
            
            $result[] = array_merge($data, $hitData);
        }

        return new JsonResponse($result);
    }
}
