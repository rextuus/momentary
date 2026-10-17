<?php

declare(strict_types=1);

namespace App\Command\Video;

use App\Entity\Video;
use App\Repository\VideoRepository;
use App\Service\Storage\StoragePathProvider;
use App\Service\Video\VideoCreationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[AsCommand(name: 'app:video:import', description: 'Importiert ein lokales Video und startet die Verarbeitung.')]
class ImportVideoCommand extends Command
{
    public function __construct(
        private readonly VideoCreationService $videoCreationService,
        private readonly StoragePathProvider $pathProvider,
        private readonly VideoRepository $videoRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'Der Pfad zur lokalen Videodatei');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getArgument('path');

        // Wenn die Datei nicht direkt existiert, prüfen, ob wir sie in app_uploads oder imports finden (falls der User einen Container-Pfad angab)
        if (!file_exists($path)) {
            // Check if it exists in storage (mapping host path if possible)
            $storageRoot = $this->pathProvider->getStorageRoot();
            $potentialPath = $storageRoot . '/' . ltrim($path, '/');

            if (file_exists($potentialPath)) {
                $path = $potentialPath;
            } else {
                $io->error('Die Datei wurde nicht gefunden: ' . $path);
                $io->note(
                    'Dies ist wahrscheinlich ein Pfad auf deinem Host-System, auf den der Container keinen Zugriff hat.',
                );
                $io->text('Du kannst die Datei mit folgendem SCP-Befehl über den SFTP-Dienst hochladen:');
                $io->text(sprintf(
                    '   scp -P 2222 "%s" uploaduser@localhost:upload/app_uploads/%s',
                    $path,
                    basename($path),
                ));
                $io->text('Anschließend kannst du den Import-Command mit dem Pfad im Container ausführen:');
                $io->text(sprintf(
                    '   docker compose exec app bin/console app:video:import /var/www/html/storage/app_uploads/%s',
                    basename($path),
                ));
                return Command::FAILURE;
            }
        }

        $filename = basename($path);
        $filesystem = new Filesystem();

        try {
            // Kopie, weil der VideoCreationService die Datei in den Storage verschiebt; das Original bleibt erhalten
            $copy = $filesystem->tempnam(sys_get_temp_dir(), 'import_');
            $filesystem->copy($path, $copy, true);
            $uploadedFile = new UploadedFile($copy, $filename, null, null, true);

            $video = new Video();
            $baseTitle = pathinfo($filename, PATHINFO_FILENAME);
            $title = $baseTitle;
            $counter = 1;
            while ($this->videoRepository->findOneBy(['title' => $title])) {
                $title = $baseTitle . '_' . $counter;
                $counter++;
            }
            $video->setTitle($title);

            $io->note('Starte VideoCreationService...');
            $this->videoCreationService->createVideo($video, $uploadedFile, null, null, true);

            $io->success('Video wurde erfolgreich importiert und die Verarbeitung gestartet: ' . $video->getTitle());
            $io->text('Status prüfen mit:');
            $io->text('docker compose exec app bin/console app:video:status ' . $video->getTitle());

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Fehler beim Import: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
