<?php

namespace App\Command\Video;

use App\Message\ExtractAllSceneThumbnailsMessage;
use App\Repository\VideoRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:video:generate-thumbnail',
    description: 'Triggers the thumbnail extraction for a given video ID',
)]
class GenerateThumbnailCommand extends Command
{
    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('videoId', InputArgument::REQUIRED, 'The video ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $videoId = (int)$input->getArgument('videoId');

        $video = $this->videoRepository->find($videoId);
        if (!$video) {
            $io->error("Video $videoId not found.");
            return Command::FAILURE;
        }

        $this->messageBus->dispatch(new ExtractAllSceneThumbnailsMessage($videoId));
        
        $io->success("Dispatched thumbnail extraction for video $videoId. Please check logs.");

        return Command::SUCCESS;
    }
}
