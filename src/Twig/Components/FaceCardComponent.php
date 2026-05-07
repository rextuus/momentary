<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\VideoFace;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
class FaceCardComponent
{
    public VideoFace $videoFace;

    public function isUnknown(): bool
    {
        $person = $this->videoFace->getPerson();
        return !$person || $person->getStatus()->value === 'unknown';
    }

    public function getEmotionEmoji(): string
    {
        return match ($this->videoFace->getEmotion()) {
            'HAPPY' => '😊',
            'SAD' => '😢',
            'ANGRY' => '😠',
            'CONFUSED' => '😕',
            'SURPRISED' => '😲',
            'CALM' => '😐',
            default => '😶',
        };
    }
}