<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:version',
    description: 'Displays the current application version',
)]
class AppVersionCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $version = file_get_contents(__DIR__ . '/../../VERSION');
        $output->writeln('Version: ' . trim($version));
        return Command::SUCCESS;
    }
}
