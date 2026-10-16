<?php

namespace App\Command\Meilisearch;

use App\Repository\VideoRepository;
use App\Service\VideoSearchIndex;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:meilisearch:index-all',
    description: 'Rebuilds the MeiliSearch index of all videos from scratch',
)]
class IndexAllVideosCommand extends Command
{
    public function __construct(
        private VideoRepository $videoRepository,
        private VideoSearchIndex $searchIndex,
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

        if ($total === 0) {
            $io->success('No videos found. Index is empty.');

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