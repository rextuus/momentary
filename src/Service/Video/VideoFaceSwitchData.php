<?php

declare(strict_types=1);

namespace App\Service\Video;

use App\Entity\Person;

class VideoFaceSwitchData
{
    private ?Person $target = null;

    public function getTarget(): ?Person
    {
        return $this->target;
    }

    public function setTarget(?Person $target): VideoFaceSwitchData
    {
        $this->target = $target;
        return $this;
    }
}
