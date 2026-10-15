<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Exception;

/**
 * Wird geworfen, wenn das Video einer Message nicht mehr existiert (z. B. gelöscht).
 * Die Middleware verwirft solche Messages still (ack), ohne Retry und ohne Failure-Transport.
 */
final class VideoGoneException extends \RuntimeException
{
    public static function forId(int $videoId): self
    {
        return new self(sprintf('Video %d existiert nicht mehr.', $videoId));
    }
}