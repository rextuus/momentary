<?php

namespace App\Command\Video;

use App\Repository\VideoRepository;
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
        $io->error('Diese Funktion ist aktuell nicht verfügbar (alte Pipeline entfernt).');
        return Command::FAILURE;
    }
}