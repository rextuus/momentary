<?php

declare(strict_types=1);

namespace App\Command\Meilisearch;

use App\Repository\PhotoRepository;
use App\Repository\VideoRepository;
use App\Service\Photo\PhotoSearchIndex;
use App\Service\VideoSearchIndex;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:meilisearch:index-all',
    description: 'Baut die Meilisearch-Indizes aller Videos und Fotos neu auf (entfernt dabei veraltete Einträge)',
)]
class IndexAllVideosCommand extends Command
{
    public function __construct(
        private VideoRepository $videoRepository,
        private VideoSearchIndex $searchIndex,
        private PhotoRepository $photoRepository,
        private PhotoSearchIndex $photoSearchIndex,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $videos = $this->videoRepository->findAll();
        $total = count($videos);

        // Index anlegen (falls nötig), Filter-Einstellungen sicherstellen und verwaiste Daten entfernen
        $this->searchIndex->clear();

        // Fotos: eigener Index
        $this->photoSearchIndex->clear();
        $photos = 0;
        foreach (array_chunk($this->photoRepository->findAll(), 100) as $chunk) {
            $photos += $this->photoSearchIndex->indexMany($chunk);
        }
        $io->text(sprintf('%d Fotos indexiert.', $photos));

        if ($total === 0) {
            $io->success('Keine Videos vorhanden, der Video-Index ist leer.');

            return Command::SUCCESS;
        }

        $io->note(sprintf('Indexing %d videos...', $total));
        $progressBar = new ProgressBar($output, $total);
        $progressBar->start();

        foreach (array_chunk($videos, 50) as $chunk) {
            $this->searchIndex->indexMany($chunk);
            $progressBar->advance(count($chunk));
        }

        $progressBar->finish();
        $io->newLine();
        $io->success('Indexing completed.');

        return Command::SUCCESS;
    }
}
