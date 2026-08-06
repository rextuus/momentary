<?php

namespace App\Command\Meilisearch;

use App\Repository\VideoRepository;
use App\Service\VideoIndexer;
use Meilisearch\Client as MeiliSearchClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:meilisearch:index-all',
    description: 'Indices all videos in MeiliSearch',
)]
class IndexAllVideosCommand extends Command
{
    public function __construct(
        private VideoRepository $videoRepository,
        private VideoIndexer $videoIndexer,
        private MeiliSearchClient $meiliSearchClient
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $videos = $this->videoRepository->findAll();
        $total = count($videos);

        if ($total === 0) {
            $io->success('No videos found to index.');
            return Command::SUCCESS;
        }

        $io->note(sprintf('Indexing %d videos...', $total));
        $progressBar = new ProgressBar($output, $total);
        $progressBar->start();

        $indexName = 'videos';
        try {
            $index = $this->meiliSearchClient->getIndex($indexName);
        } catch (\Meilisearch\Exceptions\ApiException $e) {
            $task = $this->meiliSearchClient->createIndex($indexName, ['primaryKey' => 'id']);
            $this->meiliSearchClient->waitForTask($task['taskUid']);
            $index = $this->meiliSearchClient->getIndex($indexName);
        }
        
        $index->updateFilterableAttributes(['tags', 'persons']);
        
        $batch = [];
        foreach ($videos as $video) {
            $batch[] = $this->videoIndexer->transform($video);
            
            if (count($batch) >= 100) {
                $index->addDocuments($batch);
                $batch = [];
            }
            $progressBar->advance();
        }

        if (count($batch) > 0) {
            $index->addDocuments($batch);
        }

        $progressBar->finish();
        $io->newLine();
        $io->success('Indexing completed.');

        return Command::SUCCESS;
    }
}
