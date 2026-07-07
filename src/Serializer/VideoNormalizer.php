<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\Video;
use App\Service\ImgproxyService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class VideoNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const ALREADY_CALLED = 'VIDEO_NORMALIZER_ALREADY_CALLED';

    public function __construct(
        private ImgproxyService $imgproxyService,
        private RequestStack $requestStack
    ) {}

    public function normalize($object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        // 1. Endlosschleife verhindern
        $context[self::ALREADY_CALLED] = true;

        if (!$object instanceof Video) {
            return $this->normalizer->normalize($object, $format, $context);
        }

        // 2. Query-Parameter auslesen (für activePersonScenes)
        $request = $this->requestStack->getCurrentRequest();
        $filterPersonId = null;
        $filterPersonName = null;

        if ($request) {
            $filterPersonId = $request->query->get('videoFaces_person') ?? $request->query->get('person');
            $filterPersonName = $request->query->get('videoFaces_person_name');
        }

        $isFilterActive = $filterPersonId !== null || $filterPersonName !== null;

        // 3. API Platform das Standard-Array bauen lassen
        $data = $this->normalizer->normalize($object, $format, $context);

        // 4. Sicherstellen, dass wir ein Array haben
        if (is_array($data)) {
            $groups = $context['groups'] ?? [];

            // ImgProxy URL generieren (Thumbnail)
            if (isset($data['thumbnailUrl'])) {
                $width = in_array('video:list', $groups) ? 320 : 640;
                $height = in_array('video:list', $groups) ? 180 : 360;

                $data['thumbnailUrl'] = $this->imgproxyService->generateUrl(
                    $data['thumbnailUrl'],
                    $width,
                    $height
                );
            }

            // 5. activePersonScenes befüllen
            $activePersonScenes = [];
            
            if ($isFilterActive) {
                $scenes = [];
                foreach ($object->getVideoFaces() as $face) {
                    $person = $face->getPerson();
                    if (!$person) {
                        continue;
                    }

                    $match = false;
                    if ($filterPersonId !== null && (int)$person->getId() === (int)$filterPersonId) {
                        $match = true;
                    }
                    if ($filterPersonName !== null && $person->getName() !== null && str_contains(strtolower($person->getName()), strtolower((string)$filterPersonName))) {
                        $match = true;
                    }

                    if ($match) {
                        $scene = $face->getVideoScene();
                        if ($scene) {
                            $scenes[$scene->getId()] = [
                                'id' => $scene->getId(),
                                'sceneNumber' => $scene->getSceneNumber(),
                                'startSeconds' => $scene->getStartSeconds(),
                                'endSeconds' => $scene->getEndSeconds(),
                                'title' => $scene->getTitle(),
                            ];
                        }
                    }
                }
                usort($scenes, fn($a, $b) => $a['sceneNumber'] <=> $b['sceneNumber']);
                $activePersonScenes = array_values($scenes);
            }

            $data['activePersonScenes'] = $activePersonScenes;
        }

        return $data;
    }

    public function supportsNormalization($data, ?string $format = null, array $context = []): bool
    {
        if (isset($context[self::ALREADY_CALLED])) {
            return false;
        }

        return $data instanceof Video;
    }

    public function getSupportedTypes(?string $format): array
    {
        // WICHTIG: false verhindert den Infinite-Loop durch erzwungenen Aufruf von supportsNormalization()
        return [
            Video::class => false,
        ];
    }
}
