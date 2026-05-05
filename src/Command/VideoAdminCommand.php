<?php

namespace App\Command;

use App\Repository\VideoRepository;
use App\Message\DownloadVideoMessage;
use App\Message\DetectVideoScenesMessage;
use App\Message\SplitVideoIntoFramesMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:video:admin', description: 'Interaktive Steuerung der Video-Pipeline')]
class VideoAdminCommand extends Command
{
    public function __construct(
        private VideoRepository $videoRepository,
        private MessageBusInterface $bus
    ) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $videos = $this->videoRepository->findAll();

        if (!$videos) {
            $io->warning('Keine Videos vorhanden.');
            return Command::SUCCESS;
        }

        $videoMap = [];
        foreach ($videos as $v) {
            $videoMap[$v->getTitle() . " (ID: {$v->getId()})"] = $v;
        }

        $selectedTitle = $io->choice('Welches Video soll bearbeitet werden?', array_keys($videoMap));
        $video = $videoMap[$selectedTitle];

        $step = $io->choice('Welchen Schritt triggern?', [
            'download' => 'Download starten',
            'scenes'   => 'Szenenerkennung (benötigt localPath)',
            'split'    => 'Frames extrahieren & Analyse (benötigt localPath)',
        ]);

        match ($step) {
            'download' => $this->bus->dispatch(new DownloadVideoMessage($video->getId())),
            'scenes'   => $this->bus->dispatch(new DetectVideoScenesMessage($video->getId(), (string)$video->getLocalPath())),
            'split'    => $this->bus->dispatch(new SplitVideoIntoFramesMessage($video->getId(), (string)$video->getLocalPath())),
        };

        $io->success("Job für '$step' wurde eingereiht.");
        return Command::SUCCESS;
    }
}