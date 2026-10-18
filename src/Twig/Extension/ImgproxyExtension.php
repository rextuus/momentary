<?php

declare(strict_types=1);

namespace App\Twig\Extension;

use App\Entity\File;
use App\Service\ImgproxyService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ImgproxyExtension extends AbstractExtension
{
    public function __construct(
        private readonly ImgproxyService $imgproxyService,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('imgproxy_url', [$this, 'generateUrl']),
        ];
    }

    public function generateUrl(
        ?File $source,
        int $width = 300,
        int $height = 300,
        string $resizingType = 'fill',
        int $blur = 0,
    ): string {
        if ($source === null) {
            return '';
        }

        $relativePath = ltrim($source->getRelativePath(), '/');

        // Ältere Gesichtsbilder sind ohne 'images/' gespeichert, liegen aber darunter. Fotos und Videos haben
        // eigene Bereiche im Storage und bleiben unverändert.
        if (!preg_match('#^(images|photos|videos)/#', $relativePath)) {
            $relativePath = 'images/' . $relativePath;
        }

        // Da Imgproxy-Volume auf /public/ (storage) gemappt ist,
        // ist der Pfad nun einfach local:///{relativePath}
        $sourceUrl = 'local:///' . $relativePath;

        return $this->imgproxyService->generateUrl($sourceUrl, $width, $height, $resizingType, $blur);
    }
}
