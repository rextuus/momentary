<?php

declare(strict_types=1);

namespace App\Service\Video;

use App\Entity\File;
use App\Entity\Video;
use App\Entity\VideoFace;
use App\Entity\VideoScene;
use App\Service\Face\VideoFaceRemover;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Vor einer neuen Analyse (Neuverarbeiten): alte Gesichter samt Gesichtsbildern und leeren automatischen Personen
 * (VideoFaceRemover) sowie die alten Szenen entfernen. Sonst legte die Szenenerkennung neue Szenen zusätzlich an,
 * und alte Gesichter blieben neben den neuen stehen.
 *
 * Vorschaubilder der Szenen: Die File-Zeile verschwindet, wenn nichts anderes sie nutzt; die Datei bleibt liegen
 * (wie beim Zusammenlegen leerer Szenen) und wird beim nächsten Vorschaubild überschrieben.
 */
class VideoAnalysisReset
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VideoFaceRemover $faceRemover,
    ) {}

    /**
     * @return array{faces: int, scenes: int} entfernte Gesichter und Szenen
     */
    public function reset(Video $video): array
    {
        $faces = $this->entityManager->getRepository(VideoFace::class)->count(['medium' => $video]);
        if ($faces > 0) {
            $this->faceRemover->removeAll($video);
        }

        $thumbnails = [];
        $scenes = $video->getScenes()->toArray();
        foreach ($scenes as $scene) {
            $thumbnail = $scene->getThumbnailFile();
            if ($thumbnail !== null) {
                $scene->setThumbnailFile(null);
                $thumbnails[spl_object_id($thumbnail)] = $thumbnail;
            }
            $video->removeScene($scene);
            $this->entityManager->remove($scene);
        }
        $this->entityManager->flush();

        foreach ($thumbnails as $thumbnail) {
            if ($thumbnail->getId() !== null && !$this->isReferenced($thumbnail)) {
                $this->entityManager->remove($thumbnail);
            }
        }
        $this->entityManager->flush();

        return ['faces' => $faces, 'scenes' => \count($scenes)];
    }

    private function isReferenced(File $file): bool
    {
        foreach ([
            'SELECT COUNT(s.id) FROM ' . VideoScene::class . ' s WHERE s.thumbnailFile = :file',
            'SELECT COUNT(v.id) FROM '
                . Video::class
                . ' v
                WHERE v.thumbnailFile = :file OR v.convertedFile = :file OR v.sourceFile = :file',
            'SELECT COUNT(f.id) FROM ' . VideoFace::class . ' f WHERE f.faceImage = :file',
        ] as $dql) {
            if (
                (int) $this->entityManager->createQuery($dql)->setParameter('file', $file)->getSingleScalarResult() > 0
            ) {
                return true;
            }
        }

        return false;
    }
}
