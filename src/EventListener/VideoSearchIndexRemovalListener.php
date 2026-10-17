<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Video;
use App\Service\VideoSearchIndex;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

/**
 * Entfernt ein Video aus dem Suchindex, bevor es aus der Datenbank gelöscht wird.
 * (preRemove, weil die ID nach dem Löschen nicht mehr gesetzt ist.)
 */
#[AsEntityListener(event: Events::preRemove, method: 'preRemove', entity: Video::class)]
final class VideoSearchIndexRemovalListener
{
    public function __construct(
        private readonly VideoSearchIndex $searchIndex,
        private readonly LoggerInterface $logger,
    ) {}

    public function preRemove(Video $video): void
    {
        $id = $video->getId();
        if ($id === null) {
            return;
        }

        try {
            $this->searchIndex->remove($id);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                'VideoSearchIndexRemovalListener: Video %d nicht aus Index entfernt: %s',
                $id,
                $e->getMessage(),
            ));
        }
    }
}
