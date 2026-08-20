<?php

namespace App\Command\System;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:system:reset',
    description: 'Leert die Datenbank (außer User) und alle RabbitMQ Queues',
)]
class FullResetCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$io->confirm('Sind Sie sicher, dass Sie das komplette System (DB + Queues) zurücksetzen möchten?', true)) {
            $io->warning('Abgebrochen.');
            return Command::FAILURE;
        }

        // Run queue clear
        $io->note('Führe Queue-Clear aus...');
        $queueCommand = $this->getApplication()->find('app:queue:clear');
        $queueCommand->run(new ArrayInput(['--all' => true]), $output);

        // Run database reset
        $io->note('Führe Datenbank-Reset aus...');
        $dbCommand = $this->getApplication()->find('app:database:reset');
        $dbCommand->run(new ArrayInput([]), $output);

        // Run storage reset
        $io->note('Führe Storage-Reset aus...');
        $storageCommand = $this->getApplication()->find('app:storage:reset');
        $storageCommand->run(new ArrayInput([]), $output);

        // Run meilisearch clear
        $io->note('Führe Meilisearch-Clear aus...');
        $meiliCommand = $this->getApplication()->find('app:meilisearch:clear');
        $meiliCommand->run(new ArrayInput([]), $output);

        $io->success('System wurde erfolgreich zurückgesetzt.');
        return Command::SUCCESS;
    }
}
