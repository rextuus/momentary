<?php

namespace App\Twig\Extension;

use App\Entity\File;
use App\Service\ImgproxyService;
use App\Service\Storage\StoragePathProvider;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ImgproxyExtension extends AbstractExtension
{
    public function __construct(
        private readonly ImgproxyService $imgproxyService,
        private readonly StoragePathProvider $pathProvider
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('imgproxy_url', [$this, 'generateUrl']),
        ];
    }

    public function generateUrl(?File $source, int $width = 300, int $height = 300, string $resizingType = 'fill', int $blur = 0): string
    {
        if ($source === null) {
            return '';
        }

        $relativePath = ltrim($source->getRelativePath(), '/');

        // Da Imgproxy-Volume auf /public/media/images gemappt ist,
        // müssen Dateien, die nicht in media/images liegen (wie z.B. "images/..."),
        // entsprechend für den Imgproxy-Pfad gemappt werden.
        if (str_starts_with($relativePath, 'images/')) {
            $sourceUrl = 'local:///media/' . $relativePath;
        } else {
            $sourceUrl = 'local:///' . $relativePath;
        }

        return $this->imgproxyService->generateUrl($sourceUrl, $width, $height, $resizingType, $blur);
    }
}
