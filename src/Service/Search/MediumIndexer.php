<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\Medium;
use App\Entity\Photo;
use App\Entity\Video;
use App\Service\Photo\PhotoSearchIndex;
use App\Service\VideoSearchIndex;
use Psr\Log\LoggerInterface;

/**
 * Videos und Fotos nach einer Änderung neu in den jeweiligen Suchindex schreiben. Fehler (z. B. Meilisearch nicht
 * erreichbar) werden nur protokolliert: die Änderung selbst ist gespeichert, der Index lässt sich neu aufbauen.
 */
class MediumIndexer
{
    public function __construct(
        private readonly VideoSearchIndex $videoSearchIndex,
        private readonly PhotoSearchIndex $photoSearchIndex,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param iterable<Medium> $media
     */
    public function reindex(iterable $media): void
    {
        foreach ($media as $medium) {
            try {
                if ($medium instanceof Video) {
                    $this->videoSearchIndex->index($medium);
                } elseif ($medium instanceof Photo) {
                    $this->photoSearchIndex->index($medium);
                }
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf(
                    'Suchindex für Medium %d: %s',
                    (int) $medium->getId(),
                    $e->getMessage(),
                ));
            }
        }
    }
}
