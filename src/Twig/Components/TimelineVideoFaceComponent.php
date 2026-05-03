<?php

namespace App\Twig\Components;

use App\Entity\VideoFace;
use App\Service\File\FileSystem;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class TimelineVideoFaceComponent
{
    public VideoFace $videoFace;

    public function __construct(private readonly FileSystem $filesystem)
    {
    }

    public function getVideoUrl(): ?string
    {
        if (!$this->videoFace->getFaceImagePath()) {
            return null;
        }

        return $this->filesystem->getFilesystem()->publicUrl($this->videoFace->getFaceImagePath());

    }

    public function getImageDimensions(): string
    {
        $width = 70;
        $height = 70;

        return sprintf('style="max-width: %dpx; max-height: %dpx;"',$width, $height );
    }
}
