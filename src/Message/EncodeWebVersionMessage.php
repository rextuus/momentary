<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Web-Fassung eines schon fertigen Videos erzeugen (Nachholen, siehe app:video:web-versions).
 */
final readonly class EncodeWebVersionMessage
{
    public function __construct(
        public int $videoId,
        public bool $force = false,
    ) {}
}
