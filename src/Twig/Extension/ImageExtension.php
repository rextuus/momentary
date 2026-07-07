<?php

namespace App\Twig\Extension;

use App\Entity\VideoFace;
use App\Service\ImgproxyService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ImageExtension extends AbstractExtension
{
    public function __construct(
        private readonly ImgproxyService $imgproxyService
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('display_image', [$this, 'displayImage']),
        ];
    }

    public function displayImage(VideoFace $videoFace): string
    {
        return $this->imgproxyService->generateUrl($videoFace->getFaceImagePath());
    }
}
