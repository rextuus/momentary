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
            // Index leeren, um verwaiste Daten zu entfernen
            $task = $index->deleteAllDocuments();
            $this->meiliSearchClient->waitForTask($task['taskUid']);
        } catch (\Meilisearch\Exceptions\ApiException $e) {
            $task = $this->meiliSearchClient->createIndex($indexName, ['primaryKey' => 'id']);
            $this->meiliSearchClient->waitForTask($task['taskUid']);
            $index = $this->meiliSearchClient->getIndex($indexName);
        }
        
        $index->updateFilterableAttributes(['tags', 'persons']);
        
        $batch = [];
        foreach ($videos as $video) {
            $transformed = $this->videoIndexer->transform($video);
            file_put_contents('/tmp/indexer_debug.log', 'Transforming video ' . $video->getId() . ': ' . json_encode($transformed) . PHP_EOL, FILE_APPEND);
            $batch[] = $transformed;
            
            if (count($batch) >= 100) {
                file_put_contents('/tmp/indexer_debug.log', 'Adding batch to Meilisearch...' . PHP_EOL, FILE_APPEND);
                $task = $index->addDocuments($batch);
                $this->meiliSearchClient->waitForTask($task['taskUid']);
                $batch = [];
            }
            $progressBar->advance();
        }

        if (count($batch) > 0) {
            file_put_contents('/tmp/indexer_debug.log', 'Adding final batch to Meilisearch...' . PHP_EOL, FILE_APPEND);
            $task = $index->addDocuments($batch);
            $this->meiliSearchClient->waitForTask($task['taskUid']);
        }

        $progressBar->finish();
        $io->newLine();
        $io->success('Indexing completed.');

        return Command::SUCCESS;
    }
}
