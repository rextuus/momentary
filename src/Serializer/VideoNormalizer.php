<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\Video;
use App\Service\ImgproxyService;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class VideoNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const ALREADY_CALLED = 'VIDEO_NORMALIZER_ALREADY_CALLED';

    public function __construct(private ImgproxyService $imgproxyService) {}

    public function normalize($object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        // 1. Endlosschleife verhindern
        $context[self::ALREADY_CALLED] = true;

        // 2. API Platform das Standard-Array bauen lassen
        $data = $this->normalizer->normalize($object, $format, $context);

        // 3. ImgProxy URL generieren
        if (is_array($data) && isset($data['thumbnailUrl']) && $object instanceof Video) {
            $groups = $context['groups'] ?? [];

            // Smarte 16:9 Auflösungen je nach Viewport (List vs Detail)
            $width = in_array('video:list', $groups) ? 320 : 640;
            $height = in_array('video:list', $groups) ? 180 : 360;

            $data['thumbnailUrl'] = $this->imgproxyService->generateUrl(
                $data['thumbnailUrl'],
                $width,
                $height
            );
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
