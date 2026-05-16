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
        $this->addOption('threshold', 't', InputArgument::OPTIONAL, 'Schwellenwert für Szenenerkennung', 27.0);
        $this->addOption('detector', 'd', InputArgument::OPTIONAL, 'Detektor-Typ (content oder adaptive)', 'content');
        $this->addOption('convert', 'c', null, 'Video vor der Analyse in MP4 konvertieren (hilft bei MPG-Problemen)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $videoId = (int) $input->getArgument('videoId');
        $threshold = (float) $input->getOption('threshold');
        $detector = (string) $input->getOption('detector');
        $shouldConvert = $input->getOption('convert');
        $video = $this->videoRepository->find($videoId);

        if (!$video) {
            $io->error('Video nicht gefunden.');
            return Command::FAILURE;
        }

        $io->title("Starte Szenen-Analyse für: " . $video->getTitle());

        $videoPath = $video->getLocalPath();
        $isMpg = $videoPath && str_ends_with(strtolower($videoPath), '.mpg');

        if ($isMpg && !$shouldConvert) {
            $io->warning("Es wurde eine .mpg Datei erkannt. Diese bereiten oft Probleme bei der Szenenerkennung.");
            if ($io->confirm("Soll das Video vor der Analyse automatisch nach MP4 konvertiert werden?", true)) {
                $shouldConvert = true;
            }
        }

        if (!$videoPath || !file_exists($videoPath)) {
            if (!$video->getYoutubeUrl()) {
                $io->error("Weder lokaler Pfad vorhanden noch YouTube-URL für Download.");
                return Command::FAILURE;
            }

            $io->info("Lade Video von YouTube herunter...");
            try {
                $videoPath = $this->videoAnalyzer->downloadVideo($videoId, $video->getYoutubeUrl());
            } catch (\Exception $e) {
                $io->error("Download fehlgeschlagen: " . $e->getMessage());
                return Command::FAILURE;
            }
        } else {
            $io->info("Nutze vorhandene lokale Datei: " . $videoPath);
        }

        if (!$videoPath || !file_exists($videoPath)) {
            $io->error("Video-Datei konnte nicht gefunden oder heruntergeladen werden.");
            return Command::FAILURE;
        }

        if ($shouldConvert) {
            $io->info("Konvertiere Video nach MP4 für bessere Analyse...");
            $tempMp4 = sys_get_temp_dir() . '/video_analyze_' . $videoId . '.mp4';
            
            // Wir nutzen ffmpeg direkt via Process
            $process = new \Symfony\Component\Process\Process([
                'ffmpeg', '-y', '-i', $videoPath, 
                '-c:v', 'libx264', '-preset', 'ultrafast', '-crf', '23', 
                '-c:a', 'aac', $tempMp4
            ]);
            $process->setTimeout(1800);
            $process->run(function ($type, $buffer) use ($output) {
                if ($output->isVeryVerbose()) {
                    $output->write($buffer);
                }
            });

            if ($process->isSuccessful()) {
                $videoPath = $tempMp4;
                $io->success("Konvertierung abgeschlossen.");
            } else {
                $io->error("Konvertierung fehlgeschlagen: " . $process->getErrorOutput());
                return Command::FAILURE;
            }
        }

        // 2. Szenen erkennen
        $io->info("Analysiere Schnitte (PySceneDetect) mit $detector Detektor (Threshold $threshold)...");
        try {
            $scenes = $this->videoAnalyzer->detectScenes($videoPath, $videoId, $threshold, $detector);
            // Falls das Video automatisch konvertiert wurde, hat sich der lokale Pfad geändert
            $video = $this->entityManager->find(Video::class, $videoId);
            $videoPath = $video->getLocalPath();
        } catch (\Exception $e) {
            $io->error("Fehler bei der Szenenerkennung: " . $e->getMessage());
            return Command::FAILURE;
        }

        if (empty($scenes)) {
            $io->warning("Keine Szenen erkannt. Überprüfe das Video manuell.");
            return Command::SUCCESS;
        }

        // 4. In DB speichern?
        if ($io->confirm("Sollen diese " . count($scenes) . " Szenen in die Datenbank gespeichert werden? (Bestehende Szenen werden gelöscht)", false)) {
            $this->videoAnalyzer->storeScenes($videoId, $scenes);
            $io->success("Szenen gespeichert.");
        }

        // Cleanup temp mp4
        if ($shouldConvert && isset($tempMp4) && file_exists($tempMp4)) {
            unlink($tempMp4);
        }

        // 3. Ergebnis anzeigen
        $io->success(count($scenes) . " Szenen gefunden.");

        if (count($scenes) === 1) {
            $io->note("Hinweis: Es wurde nur eine Szene erkannt. Wenn dies ein langes Video ist, probiere den 'adaptive' Detektor mit '--detector adaptive' oder verringere den Threshold mit '--threshold 20'.");
        }

        if (empty($scenes)) {
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Szene #', 'Start (s)', 'Ende (s)', 'Dauer (s)', 'Hinweis']);

        foreach ($scenes as $scene) {
            $table->addRow([
                $scene['scene_number'],
                round($scene['start_seconds'], 2),
                round($scene['end_seconds'], 2),
                round($scene['duration'], 2),
                $scene['warning'] ?? ''
            ]);
        }
        $table->render();

        return Command::SUCCESS;
    }
}