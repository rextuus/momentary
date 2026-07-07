<?php

namespace App\Twig\Extension;

use App\Service\ImgproxyService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ImgproxyExtension extends AbstractExtension
{
    public function __construct(
        private readonly ImgproxyService $imgproxyService
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('imgproxy_url', [$this, 'generateUrl']),
        ];
    }

    public function generateUrl(?string $source, int $width = 300, int $height = 300, string $resizingType = 'fill'): string
    {
        if (!$source) {
            return '';
        }

        return $this->imgproxyService->generateUrl($source, $width, $height, $resizingType);
    }
}
