<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\VideoFace;
use App\Service\ImgproxyService;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class VideoFaceNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const ALREADY_CALLED = 'VIDEO_FACE_NORMALIZER_ALREADY_CALLED';

    public function __construct(private ImgproxyService $imgproxyService) {}

    public function normalize($object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $context[self::ALREADY_CALLED] = true;

        $data = $this->normalizer->normalize($object, $format, $context);

        if (is_array($data) && isset($data['imageUrl']) && $object instanceof VideoFace) {
            $groups = $context['groups'] ?? [];

            // Quadratischer Ausschnitt für Gesichter-Thumbnails (z.B. 80x80 in Listen)
            $width = in_array('video:list', $groups) || in_array('videoface:list', $groups) ? 80 : 250;
            $height = in_array('video:list', $groups) || in_array('videoface:list', $groups) ? 80 : 250;

            $data['imageUrl'] = $this->imgproxyService->generateUrl(
                $data['imageUrl'],
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

        return $data instanceof VideoFace;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            VideoFace::class => false,
        ];
    }
}
