<?php

declare(strict_types=1);

namespace App\Twig\Extension;

use App\Entity\VideoFace;
use App\Service\ImgproxyService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ImageExtension extends AbstractExtension
{
    public function __construct(
        private readonly ImgproxyService $imgproxyService,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('display_image', [$this, 'displayImage']),
        ];
    }

    public function displayImage(VideoFace $videoFace): string
    {
        $path = $videoFace->getFaceImagePath();

        return $path === null ? '' : $this->imgproxyService->generateUrl($path);
    }
}
