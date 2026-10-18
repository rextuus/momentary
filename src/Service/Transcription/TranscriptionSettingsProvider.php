<?php

declare(strict_types=1);

namespace App\Service\Transcription;

use App\Entity\TranscriptionSettings;
use App\Repository\TranscriptionSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Liefert die aktuellen Transkriptions-Einstellungen, bei jedem Aufruf frisch aus der Datenbank.
 */
class TranscriptionSettingsProvider
{
    public function __construct(
        private readonly TranscriptionSettingsRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function get(): TranscriptionSettings
    {
        return $this->repository->findOneBy([], ['id' => 'ASC']) ?? new TranscriptionSettings();
    }

    public function save(TranscriptionSettings $settings): void
    {
        $settings->touch();
        $this->entityManager->persist($settings);
        $this->entityManager->flush();
    }

    public function reset(): void
    {
        foreach ($this->repository->findAll() as $settings) {
            $this->entityManager->remove($settings);
        }
        $this->entityManager->flush();
    }
}
