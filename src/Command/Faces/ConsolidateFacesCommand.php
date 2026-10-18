<?php

declare(strict_types=1);

namespace App\Command\Faces;

use App\Repository\VideoRepository;
use App\Service\Face\VideoFaceConsolidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Gruppiert die Gesichter eines Videos mit den aktuellen Einstellungen neu (ohne AWS-Aufrufe).
 */
#[AsCommand(
    name: 'app:faces:consolidate',
    description: 'Regroup the faces of a video into persons using the current settings',
)]
final class ConsolidateFacesCommand extends Command
{
    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly VideoFaceConsolidator $consolidator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('videoId', InputArgument::OPTIONAL, 'Video ID')->addOption(
            'all',
            null,
            InputOption::VALUE_NONE,
            'Alle Videos neu gruppieren',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('all')) {
            $videos = $this->videoRepository->findAll();
        } else {
            $videoId = $input->getArgument('videoId');
            $video = is_numeric($videoId) ? $this->videoRepository->find((int) $videoId) : null;
            if ($video === null) {
                $io->error('Video nicht gefunden. Video-ID angeben oder --all verwenden.');

                return Command::FAILURE;
            }
            $videos = [$video];
        }

        $rows = [];
        foreach ($videos as $video) {
            $result = $this->consolidator->consolidate($video);
            $rows[] = [
                $video->getId(),
                $video->getTitle(),
                $result->faces,
                $result->personsBefore,
                $result->personsAfter,
                $result->reassignedFaces,
            ];
        }

        $io->table(['ID', 'Video', 'Gesichter', 'Personen vorher', 'Personen nachher', 'umgehängt'], $rows);

        return Command::SUCCESS;
    }
}
