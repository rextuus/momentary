<?php

declare(strict_types=1);

namespace App\Service\Imgproxy;

use Onliner\ImgProxy\Options\AbstractOption;

/**
 * imgproxy-Crop mit relativer Größe (Werte < 1 = Anteil des Bildes) um einen Fokuspunkt.
 * Die Crop-Option der Bibliothek kennt nur ganze Pixel.
 */
final class RelativeCrop extends AbstractOption
{
    public function __construct(
        private readonly float $width,
        private readonly float $height,
        private readonly float $focusX,
        private readonly float $focusY,
    ) {}

    public function name(): string
    {
        return 'c';
    }

    /**
     * @return list<string>
     */
    public function data(): array
    {
        return [
            self::format($this->width),
            self::format($this->height),
            'fp',
            self::format($this->focusX),
            self::format($this->focusY),
        ];
    }

    private static function format(float $value): string
    {
        return rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
    }
}
