<?php

declare(strict_types=1);

namespace App\Service\Face;

use App\Entity\FaceRecognitionSettings;
use App\Repository\FaceRecognitionSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Liefert die aktuellen Einstellungen der Gesichtserkennung.
 *
 * Wird bei jedem Aufruf frisch gelesen, damit ein langlebiger Worker Änderungen aus der Admin-Oberfläche
 * ohne Neustart übernimmt.
 */
class FaceRecognitionSettingsProvider
{
    public function __construct(
        private readonly FaceRecognitionSettingsRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function get(): FaceRecognitionSettings
    {
        return $this->repository->findOneBy([], ['id' => 'ASC']) ?? new FaceRecognitionSettings();
    }

    /**
     * Zurück auf die Standardwerte: ohne gespeicherte Zeile gelten die Defaults der Entity.
     */
    public function reset(): void
    {
        foreach ($this->repository->findAll() as $settings) {
            $this->entityManager->remove($settings);
        }
        $this->entityManager->flush();
    }

    public function save(FaceRecognitionSettings $settings): void
    {
        $settings->touch();
        $this->entityManager->persist($settings);
        $this->entityManager->flush();
    }
}
