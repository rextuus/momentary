<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:version', description: 'Displays the current application version')]
class AppVersionCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $version = file_get_contents(__DIR__ . '/../../VERSION');
        if ($version === false) {
            $output->writeln('<error>VERSION file could not be read.</error>');

            return Command::FAILURE;
        }
        $output->writeln('Version: ' . trim($version));
        return Command::SUCCESS;
    }
}
