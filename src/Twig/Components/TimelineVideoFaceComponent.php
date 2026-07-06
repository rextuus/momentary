<?php

namespace App\Twig\Components;

use App\Entity\VideoFace;
use App\Service\ImgproxyService;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class TimelineVideoFaceComponent
{
    public VideoFace $videoFace;
    public int $width = 80;
    public int $height = 80;

    public function __construct(private readonly ImgproxyService $imgproxyService)
    {
    }

    public function getVideoUrl(): ?string
    {
        if (!$this->videoFace->getFaceImagePath()) {
            return null;
        }

        return $this->imgproxyService->generateUrl(
            $this->videoFace->getFaceImagePath(),
            $this->width,
            $this->height
        );
    }

    public function getImageDimensions(): string
    {
        return sprintf('style="max-width: %dpx; max-height: %dpx;"',$this->width, $this->height );
    }
}
