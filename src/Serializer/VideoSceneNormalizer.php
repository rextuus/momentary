<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\VideoScene;
use App\Service\ImgproxyService;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class VideoSceneNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const ALREADY_CALLED = 'VIDEO_SCENE_NORMALIZER_ALREADY_CALLED';

    public function __construct(
        private ImgproxyService $imgproxyService
    ) {}

    public function normalize($object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $context[self::ALREADY_CALLED] = true;

        if (!$object instanceof VideoScene) {
            return $this->normalizer->normalize($object, $format, $context);
        }

        $data = $this->normalizer->normalize($object, $format, $context);

        if (is_array($data) && isset($data['thumbnailUrl'])) {
            $data['thumbnailUrl'] = $this->imgproxyService->generateUrl(
                $data['thumbnailUrl'],
                320,
                180
            );
        }

        return $data;
    }

    public function supportsNormalization($data, ?string $format = null, array $context = []): bool
    {
        if (isset($context[self::ALREADY_CALLED])) {
            return false;
        }

        return $data instanceof VideoScene;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            VideoScene::class => false,
        ];
    }
}
