<?php

declare(strict_types=1);

namespace App\Command\Video;

use App\Repository\VideoRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:video:status',
    description: 'Zeigt den Status eines Videos und den Fortschritt der Verarbeitung.',
)]
class VideoStatusCommand extends Command
{
    public function __construct(
        private readonly VideoRepository $videoRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Die ID oder der Titel des Videos');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $identifier = $input->getArgument('id');

        // Suche per ID, falls numerisch, sonst per Titel
        $video = is_numeric($identifier)
            ? $this->videoRepository->find((int) $identifier)
            : $this->videoRepository->findOneBy(['title' => $identifier]);

        if (!$video) {
            // Fallback: Wenn per Titel nichts gefunden wurde, versuche eine Suche über alle (evtl. hat sich der Titel geändert oder ist nicht eindeutig)
            // Aber hier geben wir erst mal eine Nachricht aus, falls nichts gefunden wurde.
            $io->error('Video nicht gefunden: ' . $identifier);
            return Command::FAILURE;
        }

        $io->title('Status für Video: ' . $video->getTitle() . ' (ID: ' . $video->getId() . ')');

        $io->table(['Attribut', 'Wert'], [
            ['Status', $video->getStatus()->value],
            ['Szenen', count($video->getScenes())],
            ['Gefundene Gesichter/Personen', count($video->getVideoFaces())],
            ['Verarbeitete Frames', $video->getProcessedFrames() . ' / ' . $video->getTotalFrames()],
            ['Erstellt am', $video->getCreatedAt()?->format('d.m.Y H:i:s')],
        ]);

        return Command::SUCCESS;
    }
}
