<?php

declare(strict_types=1);

namespace App\Command\Video;

use App\Repository\VideoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Native Bildhöhe eines Videos setzen, das schon hochskaliert importiert wurde (z. B. 576 für ein auf 4K
 * hochgerechnetes PAL-Band). Die Mindestgröße der Gesichtserkennung bezieht sich darauf; ohne Angabe gilt die Höhe
 * der Datei, die ffprobe beim ersten Verarbeiten misst.
 */
#[AsCommand(
    name: 'app:video:source-height',
    description: 'Zeigt oder setzt die native Bildhöhe (Zeilen) eines Videos, z. B. 576 bei hochskaliertem PAL-Material.',
)]
final class SourceHeightCommand extends Command
{
    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('videoId', InputArgument::REQUIRED, 'ID des Videos')->addArgument(
            'height',
            InputArgument::OPTIONAL,
            'Native Höhe in Zeilen (z. B. 480, 576); weglassen = anzeigen',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $video = $this->videoRepository->find((int) $input->getArgument('videoId'));
        if ($video === null) {
            $io->error('Video nicht gefunden.');

            return Command::FAILURE;
        }

        $height = $input->getArgument('height');
        if ($height === null) {
            $io->writeln(sprintf(
                'Video %d "%s": native Höhe %s, Halbbilder %s',
                (int) $video->getId(),
                (string) $video->getTitle(),
                $video->getSourceHeight() !== null ? $video->getSourceHeight() . ' Zeilen' : 'unbekannt',
                $video->getFieldOrder() ?? 'nicht geprüft',
            ));

            return Command::SUCCESS;
        }

        if (!ctype_digit((string) $height) || (int) $height < 100 || (int) $height > 4320) {
            $io->error('Höhe muss eine Zahl zwischen 100 und 4320 sein.');

            return Command::INVALID;
        }

        $video->setSourceHeight((int) $height);
        $this->entityManager->flush();
        $io->success(sprintf(
            'Native Höhe von Video %d auf %d Zeilen gesetzt. Gilt für ab jetzt analysierte Frames.',
            (int) $video->getId(),
            (int) $height,
        ));

        return Command::SUCCESS;
    }
}
