<?php

namespace App\Command;

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
    description: 'Weist Videos einen Standard-Thumbnail-Pfad für Imgproxy-Tests zu.',
)]
class VideoSetDefaultThumbnailCommand extends Command
{
    public function __construct(
        private VideoRepository $videoRepository,
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
            'Überschreibt auch Videos, die bereits einen Thumbnail-Pfad haben.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = $input->getOption('force');

        // Dummy-Pfad, den dein Imgproxy-System/Storage auflösen kann
        $defaultPath = 'defaults/video-placeholder.jpg';

        $videos = $this->videoRepository->findAll();
        $updatedCount = 0;

        if (empty($videos)) {
            $io->warning('Keine Videos in der Datenbank gefunden.');
            return Command::SUCCESS;
        }

        $io->comment(sprintf('Starte Zuweisung des Default-Thumbnails ("%s")...', $defaultPath));
        $io->progressStart(count($videos));

        foreach ($videos as $video) {
            // Wenn 'force' nicht aktiv ist, überspringen wir Videos mit existierendem Pfad
            if (!$force && $video->getConvertedVideoPath() !== null) {
                $io->progressAdvance();
                continue;
            }

            $video->setConvertedVideoPath($defaultPath);
            $updatedCount++;
            $io->progressAdvance();
        }

        $this->entityManager->flush();
        $io->progressFinish();

        $io->success(sprintf('Fertig! %d von %d Videos wurden aktualisiert.', $updatedCount, count($videos)));

        return Command::SUCCESS;
    }
}
