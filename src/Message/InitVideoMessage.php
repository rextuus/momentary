<?php

namespace App\Message;

final readonly class InitVideoMessage
{

    public function __construct(private string $videoId)
    {
    }

    public function getVideoId(): string
    {
        return $this->videoId;
    }


}
