<?php

namespace App\Command;

use App\Repository\VideoRepository;
use App\Service\JellyfinUploadService;
use App\Service\VideoAnalyzer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:jellyfin:test-export',
    description: 'Exportiert ein Video zu Jellyfin zum Testen der API-Anbindung.',
)]
class JellyfinTestExportCommand extends Command
{
    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly JellyfinUploadService $jellyfinUploadService,
        private readonly VideoAnalyzer $videoAnalyzer
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Die ID des zu exportierenden Videos');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $videoId = (int) $input->getArgument('id');

        $video = $this->videoRepository->find($videoId);

        if (!$video) {
            $io->error(sprintf('Video mit ID %d wurde nicht gefunden.', $videoId));
            return Command::FAILURE;
        }

        $sourcePath = $this->videoAnalyzer->resolvePath($video->getLocalPath());
        if (!$sourcePath || !file_exists($sourcePath)) {
            $io->error(sprintf('Lokale Videodatei für Video %d existiert nicht unter: %s', $videoId, $sourcePath ?? 'null'));
            return Command::FAILURE;
        }

        $io->info(sprintf('Starte Export für Video: %s (ID: %d)', $video->getTitle(), $video->getId()));
        $io->comment(sprintf('Pfad: %s', $sourcePath));

        $filename = basename($sourcePath);
        $io->text('Kopiere Datei nach Jellyfin...');
        $result = $this->jellyfinUploadService->uploadVideo($sourcePath, $filename);

        if ($result) {
            $io->text('Datei kopiert. Trigger Jellyfin Scan...');
            $io->success(sprintf('Video erfolgreich nach Jellyfin exportiert: %s', $result));
            return Command::SUCCESS;
        }

        $io->error('Fehler beim Export nach Jellyfin. Überprüfe die Logs für Details.');
        return Command::FAILURE;
    }
}
