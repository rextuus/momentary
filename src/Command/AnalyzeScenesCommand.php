<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\VideoRepository;
use App\Service\VideoAnalyzer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:video:analyze-scenes', description: 'Testet die lokale Szenenerkennung')]
class AnalyzeScenesCommand extends Command
{
    public function __construct(
        private VideoRepository $videoRepository,
        private VideoAnalyzer $videoAnalyzer
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('videoId', InputArgument::REQUIRED, 'Die ID des Videos in der DB');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $videoId = (int) $input->getArgument('videoId');
        $video = $this->videoRepository->find($videoId);

        if (!$video) {
            $io->error('Video nicht gefunden.');
            return Command::FAILURE;
        }

        $io->title("Starte Szenen-Analyse für: " . $video->getTitle());

        // 1. Video herunterladen (falls noch nicht geschehen oder Pfad holen)
        $io->info("Lade Video herunter / Prüfe Pfad...");
        $videoPath = $this->videoAnalyzer->downloadVideo($videoId, $video->getYoutubeUrl());

        if (!$videoPath) {
            $io->error("Video konnte nicht bereitgestellt werden.");
            return Command::FAILURE;
        }

        // 2. Szenen erkennen
        $io->info("Analysiere Schnitte (PySceneDetect)...");
        $scenes = $this->videoAnalyzer->detectScenes($videoPath);

        // 3. Ergebnis anzeigen
        $io->success(count($scenes) . " Szenen gefunden.");

        $table = new Table($output);
        $table->setHeaders(['Szene #', 'Start (s)', 'Ende (s)', 'Dauer (s)']);

        foreach ($scenes as $scene) {
            $table->addRow([
                $scene['scene_number'],
                round($scene['start_seconds'], 2),
                round($scene['end_seconds'], 2),
                round($scene['duration'], 2)
            ]);
        }
        $table->render();

        return Command::SUCCESS;
    }
}