<?php

namespace App\Twig\Components;

use App\Entity\VideoFace;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class VideoFaceListing
{
    use DefaultActionTrait;

    /**
     * @var array<VideoFace>
     */
    #[LiveProp]
    public array $videoFaces = [];

    #[LiveProp(writable: true)]
    public ?int $count = 0;
}
