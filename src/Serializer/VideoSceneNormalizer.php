<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\VideoScene;
use App\Service\ImgproxyService;
use App\Service\JellyfinPlaybackService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class VideoSceneNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const ALREADY_CALLED = 'VIDEO_SCENE_NORMALIZER_ALREADY_CALLED';

    public function __construct(
        private ImgproxyService $imgproxyService,
        private Security $security,
        private JellyfinPlaybackService $jellyfinPlaybackService
    ) {}

    public function normalize($object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $context[self::ALREADY_CALLED] = true;

        if (!$object instanceof VideoScene) {
            return $this->normalizer->normalize($object, $format, $context);
        }

        $data = $this->normalizer->normalize($object, $format, $context);

        if (is_array($data)) {
            $data['isPublic'] = $object->isPublic();
            $data['playbackUrl'] = $this->jellyfinPlaybackService->generatePlaybackUrl($object);
        }

        if (is_array($data) && isset($data['thumbnailUrl'])) {
            $isAllowed = $this->security->isGranted('SCENE_VIEW', $object);
            $blur = $isAllowed ? 0 : 5;

            // Add cache buster based on public status to ensure fresh image generation on change
            $thumbnailUrl = $data['thumbnailUrl'];
            $cacheBuster = 'v=' . ($object->isPublic() ? 'p' : 'priv');
            $thumbnailUrl .= (str_contains($thumbnailUrl, '?') ? '&' : '?') . $cacheBuster;

            $data['thumbnailUrl'] = $this->imgproxyService->generateUrl(
                $thumbnailUrl,
                320,
                180,
                'fill',
                $blur
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
