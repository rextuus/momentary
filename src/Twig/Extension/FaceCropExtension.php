<?php

namespace App\Twig\Extension;

use App\Service\ImgproxyService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class FaceCropExtension extends AbstractExtension
{
    public function __construct(
        private readonly ImgproxyService $imgproxyService
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('face_zoom_style', [$this, 'getFaceZoomStyle']),
            new TwigFunction('imgproxy_url', [$this, 'getImgproxyUrl']),
        ];
    }

    public function getImgproxyUrl(string $path, int $width = 300, int $height = 300, string $resizingType = 'fill'): string
    {
        return $this->imgproxyService->generateUrl($path, $width, $height, $resizingType);
    }

    /**
     * Erstellt den fertigen Style-String inklusive Bild-URL.
     * zoomFactor 0.05 = Sehr weit weg (Porträt)
     * zoomFactor 0.5  = Fokus auf Gesicht
     */
    public function getFaceZoomStyle(string $imagePath, $boundingBox, float $zoomFactor = 0.1): string
    {
        if (!$boundingBox) {
            return sprintf(
                'background-image: url("%s"); background-size: cover; background-position: center; width: 100%%; height: 100%%;',
                $imagePath
            );
        }

        $box = (object) $boundingBox;

        // Falls zoomFactor 0 ist, zeigen wir das ganze Bild an
        if ($zoomFactor <= 0) {
            return sprintf(
                'background-image: url("%s"); background-size: 100%% 100%%; background-repeat: no-repeat; background-position: center; width: 100%%; height: 100%%;',
                $imagePath
            );
        }

        // Falls zoomFactor exakt 1 ist, nutzen wir eine präzise mathematische Zentrierung (wird aktuell nicht genutzt, aber als Fallback)
        if ($zoomFactor >= 1.0) {
            return sprintf(
                'background-image: url("%s"); background-size: %f%% %f%%; background-position: %f%% %f%%; width: 100%%; height: 100%%;',
                $imagePath,
                100 / max(0.01, $box->Width),
                100 / max(0.01, $box->Height),
                ($box->Left / max(0.01, 1 - $box->Width)) * -100,
                ($box->Top / max(0.01, 1 - $box->Height)) * -100
            );
        }

        // Fokuspunkt berechnen
        $faceCenterX = ($box->Left + ($box->Width / 2)) * 100;
        $faceCenterY = ($box->Top + ($box->Height / 2)) * 100;

        // Gewichtung zwischen Bildmitte (50%) und Gesicht
        $posX = (50 * (1 - $zoomFactor)) + ($faceCenterX * $zoomFactor);
        $posY = (50 * (1 - $zoomFactor)) + ($faceCenterY * $zoomFactor);

        return sprintf(
            'background-image: url("%s"); background-size: cover; background-position: %f%% %f%%; width: 100%%; height: 100%%;',
            $imagePath,
            $posX,
            $posY
        );
    }
}