<?php

declare(strict_types=1);

namespace App\Command\Media;

use App\Repository\UserRepository;
use App\Service\Media\ImportFolder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:media:import-folder',
    description: 'Übernimmt fertig hochgeladene Videos und Fotos aus dem Import-Ordner (storage/imports)',
)]
final class ImportFolderCommand extends Command
{
    public function __construct(
        private readonly ImportFolder $importFolder,
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('owner', null, InputOption::VALUE_REQUIRED, 'E-Mail des Eigentümers')->addOption(
            'list',
            null,
            InputOption::VALUE_NONE,
            'Nur anzeigen, nichts übernehmen',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('list')) {
            $io->table(['Datei', 'Typ', 'Gruppe', 'Status'], array_map(static fn($file): array => [
                $file->relativePath,
                $file->type,
                $file->batch ?? '–',
                ($file->ready ? 'bereit' : 'wird noch hochgeladen?')
                    . ($file->likelyDuplicate ? ', vermutlich schon vorhanden' : ''),
            ], $this->importFolder->files()));

            return Command::SUCCESS;
        }

        $owner = null;
        if ($input->getOption('owner') !== null) {
            $owner = $this->userRepository->findOneBy(['email' => $input->getOption('owner')]);
            if ($owner === null) {
                $io->error(sprintf('Kein Benutzer mit der E-Mail "%s".', $input->getOption('owner')));

                return Command::FAILURE;
            }
        }

        $count = $this->importFolder->queue($owner);
        $io->success(sprintf('%d Datei(en) zur Übernahme eingeplant (verarbeitet vom messenger-worker).', $count));

        return Command::SUCCESS;
    }
}
