<?php

declare(strict_types=1);

namespace App\Command\Video;

use App\Repository\VideoRepository;
use App\Service\Storage\StoragePathProvider;
use App\Service\Video\Analyze\BetterVideoAnalyzer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Trägt für Videos ohne Aufnahmedatum das Datum aus den Dateimetadaten nach (für Videos, die vor dieser Funktion
 * importiert wurden). Neue Videos bekommen es automatisch bei der Konvertierung.
 */
#[AsCommand(
    name: 'app:video:recorded-at',
    description: 'Liest fehlende Aufnahmedaten von Videos aus den Dateimetadaten',
)]
final class BackfillRecordedAtCommand extends Command
{
    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly StoragePathProvider $pathProvider,
        private readonly BetterVideoAnalyzer $analyzer,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $updated = 0;
        $missing = 0;

        foreach ($this->videoRepository->findBy(['takenAt' => null]) as $video) {
            $file = $video->getSourceFile() ?? $video->getConvertedFile();
            $path = $file !== null ? $this->pathProvider->getAbsolutePath($file) : null;
            $recordedAt = $path !== null && is_file($path) ? $this->analyzer->probeRecordedAt($path) : null;
            if ($recordedAt === null) {
                $missing++;
                continue;
            }
            $video->setTakenAt($recordedAt);
            $io->text(sprintf('Video %d: %s', (int) $video->getId(), $recordedAt->format('d.m.Y H:i')));
            $updated++;
        }
        $this->entityManager->flush();

        $io->success(sprintf('%d Aufnahmedatum/-daten nachgetragen, %d ohne Angabe in der Datei.', $updated, $missing));

        return Command::SUCCESS;
    }
}
