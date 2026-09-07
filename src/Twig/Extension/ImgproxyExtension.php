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

        // Wir prüfen, ob der Pfad mit 'images/' beginnt. Falls nicht, fügen wir 'images/' hinzu,
        // da alle durch FrameAnalyzer erstellten Face-Bilder nun unter 'images/faces/' liegen.
        if (!str_starts_with($relativePath, 'images/')) {
            $relativePath = 'images/' . $relativePath;
        }

        // Da Imgproxy-Volume auf /public/ (storage) gemappt ist,
        // ist der Pfad nun einfach local:///{relativePath}
        $sourceUrl = 'local:///' . $relativePath;

        return $this->imgproxyService->generateUrl($sourceUrl, $width, $height, $resizingType, $blur);
    }
}
