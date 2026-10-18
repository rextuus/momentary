<?php

declare(strict_types=1);

namespace App\Command\Photo;

use App\Repository\UserRepository;
use App\Service\Photo\PhotoImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

#[AsCommand(
    name: 'app:photo:import',
    description: 'Importiert Fotos (Dateien oder Ordner, rekursiv) und plant ihre Analyse ein',
)]
final class ImportPhotosCommand extends Command
{
    public function __construct(
        private readonly PhotoImporter $importer,
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'paths',
            InputArgument::REQUIRED | InputArgument::IS_ARRAY,
            'Fotodateien oder Ordner',
        )->addOption('owner', null, InputOption::VALUE_REQUIRED, 'E-Mail des Eigentümers');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $owner = null;
        if ($input->getOption('owner') !== null) {
            $owner = $this->userRepository->findOneBy(['email' => $input->getOption('owner')]);
            if ($owner === null) {
                $io->error(sprintf('Kein Benutzer mit der E-Mail "%s".', $input->getOption('owner')));

                return Command::FAILURE;
            }
        }

        $files = [];
        foreach ((array) $input->getArgument('paths') as $path) {
            if (is_dir($path)) {
                foreach (new Finder()
                    ->files()
                    ->in($path)
                    ->sortByName() as $file) {
                    if (PhotoImporter::isSupported($file->getFilename())) {
                        $files[] = $file->getPathname();
                    }
                }
            } elseif (is_file($path)) {
                $files[] = $path;
            } else {
                $io->warning(sprintf('"%s" nicht gefunden.', $path));
            }
        }

        $imported = 0;
        $duplicates = 0;
        foreach ($io->progressIterate($files) as $file) {
            try {
                $result = $this->importer->import($file, $owner);
                $result->duplicate ? $duplicates++ : $imported++;
            } catch (\Throwable $e) {
                $io->warning(sprintf('%s: %s', basename($file), $e->getMessage()));
            }
        }

        $io->success(sprintf(
            '%d Foto(s) importiert und zur Analyse eingeplant, %d bereits vorhanden.',
            $imported,
            $duplicates,
        ));

        return Command::SUCCESS;
    }
}
