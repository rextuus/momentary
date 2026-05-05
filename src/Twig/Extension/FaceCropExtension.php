<?php

namespace App\Twig\Extension;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class FaceCropExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('face_zoom_style', [$this, 'getFaceZoomStyle']),
        ];
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

        // Fokuspunkt berechnen
        $faceCenterX = ($box->Left + ($box->Width / 2)) * 100;
        $faceCenterY = ($box->Top + ($box->Height / 2)) * 100;

        // Gewichtung zwischen Bildmitte (50%) und Gesicht
        $posX = (50 * (1 - $zoomFactor)) + ($faceCenterX * $zoomFactor);
        $posY = (50 * (1 - $zoomFactor)) + ($faceCenterY * $zoomFactor);

        return sprintf(
            'background-image: url("%s"); background-size: cover; background-position: %f%% %f%%; width: 100%%; height: 100%%; position: absolute; top: 0; left: 0;',
            $imagePath,
            $posX,
            $posY
        );
    }
}