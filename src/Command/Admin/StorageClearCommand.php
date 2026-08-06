<?php

namespace App\Command\Admin;

use App\Service\PathConstants;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;

#[AsCommand(
    name: 'app:storage:clear',
    description: 'Leert die Upload-Verzeichnisse (Jellyfin Uploads & App Uploads)',
)]
class StorageClearCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $pathsToClear = [
            $this->projectDir . '/' . PathConstants::JELLYFIN_UPLOADS,
            $this->projectDir . '/' . PathConstants::APP_UPLOADS,
        ];

        $filesToList = [];
        foreach ($pathsToClear as $path) {
            if (is_dir($path)) {
                $finder = new Finder();
                $finder->in($path)->depth(0);
                foreach ($finder as $file) {
                    $relativePathToPath = substr($path, strlen($this->projectDir) + 1);
                    $filesToList[] = $relativePathToPath . DIRECTORY_SEPARATOR . $file->getRelativePathname();
                }
            }
        }

        if (!empty($filesToList)) {
            $io->text('Folgende Dateien/Verzeichnisse werden gelöscht:');
            $io->listing($filesToList);
        } else {
            $io->note('Keine Dateien zum Löschen in den Upload-Verzeichnissen gefunden.');
        }

        if (!$io->confirm('Sind Sie sicher, dass Sie alle Upload-Verzeichnisse leeren möchten? Das kann nicht rückgängig gemacht werden.', false)) {
            $io->warning('Abgebrochen.');
            return Command::FAILURE;
        }

        foreach ($pathsToClear as $path) {
            if (!is_dir($path)) {
                $io->note(sprintf('Verzeichnis existiert nicht: %s', $path));
                continue;
            }

            $io->note(sprintf('Leere Verzeichnis: %s', $path));
            
            $finder = new Finder();
            // Finder findet Dateien und Verzeichnisse, depth(0) begrenzt auf die erste Ebene
            $finder->in($path)->depth(0);
            
            foreach ($finder as $file) {
                if ($file->isDir()) {
                    // Verzeichnisse rekursiv löschen
                    $this->removeDirectory($file->getRealPath());
                } else {
                    // Dateien löschen
                    unlink($file->getRealPath());
                }
            }
        }

        $io->success('Upload-Verzeichnisse wurden erfolgreich geleert.');
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
