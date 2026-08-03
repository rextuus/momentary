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

    public function __construct(
        private ImgproxyService $imgproxyService
    ) {}

    public function normalize($object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        // 1. Endlosschleife verhindern
        $context[self::ALREADY_CALLED] = true;

        if (!$object instanceof Video) {
            return $this->normalizer->normalize($object, $format, $context);
        }

        // 3. API Platform das Standard-Array bauen lassen
        $data = $this->normalizer->normalize($object, $format, $context);

        // 4. Sicherstellen, dass wir ein Array haben
        if (is_array($data)) {
            $groups = (array) ($context['groups'] ?? []);

            // ImgProxy URL generieren (Thumbnail)
            // Wir entfernen die automatische Signierung hier, damit wir in den Controllern 
            // die URLs dynamisch mit Parametern (wie blur) signieren können.
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
