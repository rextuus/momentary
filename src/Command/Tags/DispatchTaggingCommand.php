<?php

namespace App\Command\Tags;

use App\Message\TagScenesMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:tags:dispatch',
    description: 'Dispatch TagScenesMessage for a video',
)]
class DispatchTaggingCommand extends Command
{
    public function __construct(
        private MessageBusInterface $messageBus
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('videoId', InputArgument::REQUIRED, 'The video ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $videoId = $input->getArgument('videoId');
        $this->messageBus->dispatch(new TagScenesMessage((int)$videoId));
        $output->writeln("Dispatched TagScenesMessage for video $videoId");
        return Command::SUCCESS;
    }
}
