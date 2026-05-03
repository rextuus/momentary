<?php

namespace App\Twig\Extension;

use App\Entity\VideoFace;
use App\Service\File\FileSystem;
use App\Twig\Runtime\ImageExtensionRuntime;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class ImageExtension extends AbstractExtension
{


    public function __construct(
        private readonly FileSystem $filesystem
    )
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('display_image', [$this, 'displayImage']),
        ];
    }

    public function displayImage(VideoFace $videoFace): string
    {
        return $this->filesystem->getFilesystem()->publicUrl($videoFace->getFaceImagePath());

    }
}
