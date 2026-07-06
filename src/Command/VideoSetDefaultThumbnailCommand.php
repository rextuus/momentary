<?php

namespace App\Command;

use App\Repository\VideoFaceRepository;
use App\Repository\VideoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:video:set-default-thumbnail',
    description: 'Weist Videos und VideoFaces Standard-Pfade für Imgproxy-Tests zu.',
)]
class VideoSetDefaultThumbnailCommand extends Command
{
    public function __construct(
        private VideoRepository $videoRepository,
        private VideoFaceRepository $videoFaceRepository,
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Überschreibt auch Einträge, die bereits einen Pfad konfiguriert haben.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = $input->getOption('force');

        $defaultVideoPath = 'defaults/video-placeholder.jpg';
        $defaultFacePath = 'defaults/face-placeholder.jpg';

        // --- 1. VIDEOS VERARBEITEN ---
        $videos = $this->videoRepository->findAll();
        $updatedVideos = 0;

        if (!empty($videos)) {
            $io->section('Verarbeite Videos...');
            $io->progressStart(count($videos));

            foreach ($videos as $video) {
                if (!$force && $video->getConvertedVideoPath() !== null) {
                    $io->progressAdvance();
                    continue;
                }

                $video->setConvertedVideoPath($defaultVideoPath);
                $updatedVideos++;
                $io->progressAdvance();
            }
            $io->progressFinish();
        } else {
            $io->warning('Keine Videos in der Datenbank gefunden.');
        }

        // --- 2. VIDEO FACES VERARBEITEN ---
        $faces = $this->videoFaceRepository->findAll();
        $updatedFaces = 0;

        if (!empty($faces)) {
            $io->section('Verarbeite Video Faces...');
            $io->progressStart(count($faces));

            foreach ($faces as $face) {
                if (!$force && $face->getFaceImagePath() !== null) {
                    $io->progressAdvance();
                    continue;
                }

                $face->setFaceImagePath($defaultFacePath);
                $updatedFaces++;
                $io->progressAdvance();
            }
            $io->progressFinish();
        } else {
            $io->warning('Keine VideoFaces in der Datenbank gefunden.');
        }

        // Alles auf einmal in die DB schreiben
        if ($updatedVideos > 0 || $updatedFaces > 0) {
            $this->entityManager->flush();
        }

        $io->success(sprintf(
            'Fertig! Aktualisiert: %d Videos und %d VideoFaces.',
            $updatedVideos,
            $updatedFaces
        ));

        return Command::SUCCESS;
    }
}