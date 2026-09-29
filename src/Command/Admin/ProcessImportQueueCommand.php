<?php

namespace App\Command\Admin;

use App\Service\Video\Queue\ImportQueueManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:process-import-queue', description: 'Processes videos in the import queue')]
class ProcessImportQueueCommand extends Command
{
    public function __construct(
        private readonly ImportQueueManager $importQueueManager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $started = $this->importQueueManager->processQueue();

        if ($started > 0) {
            $io->success("Started processing $started new videos.");
        } else {
            $io->note("No new videos started (limit reached or queue empty).");
        }

        return Command::SUCCESS;
    }
}
