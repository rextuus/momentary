<?php

namespace App\Command\Files;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

#[AsCommand(name: 'app:files:move-uploaded', description: 'Moves files from SFTP upload directories to imports')]
class MoveUploadedFilesCommand extends Command
{
    private const TARGET_DIR = '/var/www/html/public/uploads/import';

    public function __construct(
        #[Autowire(param: 'app.ftp_source_dirs')]
        private array $sourceDirs
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fs = new Filesystem();

        if (!$fs->exists(self::TARGET_DIR)) {
            $fs->mkdir(self::TARGET_DIR);
        }

        $count = 0;
        foreach ($this->sourceDirs as $sourceDir) {
            if (!$fs->exists($sourceDir)) {
                $io->warning('Source directory not found, skipping: ' . $sourceDir);
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($sourceDir);

            foreach ($finder as $file) {
                $targetPath = self::TARGET_DIR . DIRECTORY_SEPARATOR . $file->getFilename();
                
                // Avoid overwriting if file already exists
                if ($fs->exists($targetPath)) {
                    $io->note('File already exists in target, skipping: ' . $file->getFilename());
                    continue;
                }
                
                $fs->rename($file->getRealPath(), $targetPath);
                $io->text('Moved: ' . $file->getFilename());
                $count++;
            }
        }

        $io->success(sprintf('Successfully moved %d files.', $count));

        return Command::SUCCESS;
    }
}
