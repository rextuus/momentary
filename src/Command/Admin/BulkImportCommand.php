<?php

declare(strict_types=1);

namespace App\Command\Admin;

use App\Entity\Video;
use App\Enum\VideoStatus;
use App\Repository\VideoRepository;
use App\Service\Storage\StoragePathProvider;
use App\Service\Video\Queue\ImportQueueManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:bulk-import', description: 'Scans import directory and queues videos')]
class BulkImportCommand extends Command
{
    public function __construct(
        private readonly StoragePathProvider $pathProvider,
        private readonly EntityManagerInterface $entityManager,
        private readonly VideoRepository $videoRepository,
        private readonly ImportQueueManager $importQueueManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $importDir = $this->pathProvider->getImportAbsolutePath();

        if (!is_dir($importDir)) {
            $io->error("Import directory does not exist: $importDir");
            return Command::FAILURE;
        }

        $files = scandir($importDir);
        $allowedExtensions = ['mp4', 'mov', 'avi', 'mkv', 'webm'];
        $imported = 0;

        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || is_dir($importDir . DIRECTORY_SEPARATOR . $file)) {
                continue;
            }

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExtensions, true)) {
                continue;
            }

            if ($this->videoRepository->findOneBy(['title' => $file])) {
                $io->warning("Video already exists: $file");
                continue;
            }

            $video = new Video();
            $video->setTitle($file);
            $video->setStatus(VideoStatus::QUEUED);
            $video->setCreatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($video);
            $imported++;
        }

        $this->entityManager->flush();
        $this->importQueueManager->processQueue();
        $io->success("Imported $imported files.");

        return Command::SUCCESS;
    }
}
