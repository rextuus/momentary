<?php

declare(strict_types=1);

namespace App\Service\Face;

/**
 * Das Foto (bzw. Medium), dessen Gesichter gerade gespeichert werden sollten, wurde inzwischen gelöscht.
 * Für Videos gibt es VideoGoneException, die die Messenger-Middleware still verwirft.
 */
final class MediumGoneException extends \RuntimeException
{
    public static function forId(int $mediumId): self
    {
        return new self(sprintf('Medium %d existiert nicht mehr.', $mediumId));
    }
}
