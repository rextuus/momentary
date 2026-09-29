<?php

namespace App\Command\Admin;

use App\Service\Storage\StoragePathProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

#[AsCommand(
    name: 'app:storage:reset',
    description: 'Leert das gesamte Storage-Verzeichnis (Videos, Thumbnails, etc.)',
)]
class StorageResetCommand extends Command
{
    public function __construct(
        private readonly StoragePathProvider $pathProvider
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $storageRoot = $this->pathProvider->getStorageRoot();

        if (is_dir($storageRoot)) {
            $finder = new Finder();
            $finder->in($storageRoot)->depth(0);

            foreach ($finder as $item) {
                if ($item->isDir()) {
                    $io->text('Verzeichnis: ' . $item->getRelativePathname());

                    $subFinder = new Finder();
                    $subFinder->in($item->getRealPath())->files(); // Nur Dateien
                    
                    $count = 0;
                    foreach ($subFinder as $file) {
                        $io->text('  - ' . $file->getRelativePathname());
                        $count++;
                        if ($count >= 10) {
                            $io->text('  - ... (weitere Dateien)');
                            break;
                        }
                    }
                } else {
                    $io->text('Datei: ' . $item->getRelativePathname());
                }
            }
        }

        if (!$io->confirm('Sind Sie sicher, dass Sie das gesamte Storage-Verzeichnis leeren möchten? Das kann nicht rückgängig gemacht werden.', true)) {
            $io->warning('Abgebrochen.');
            return Command::FAILURE;
        }

        $io->note(sprintf('Leere Verzeichnis: %s', $storageRoot));

        if (!is_dir($storageRoot)) {
            $io->warning('Storage-Verzeichnis existiert nicht.');
            return Command::SUCCESS;
        }

        $finder = new Finder();
        $finder->in($storageRoot)->depth(0);

        foreach ($finder as $file) {
            if ($file->isDir()) {
                $this->removeDirectory($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        $io->success('Storage-Verzeichnis wurde erfolgreich geleert.');
        return Command::SUCCESS;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
