<?php

namespace App\Command\Meilisearch;

use App\Repository\VideoRepository;
use App\Service\VideoIndexer;
use Meilisearch\Client as MeiliSearchClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:meilisearch:index',
    description: 'Indices a single video in MeiliSearch',
)]
class IndexCommand extends Command
{
    public function __construct(
        private VideoRepository $videoRepository,
        private VideoIndexer $videoIndexer,
        private MeiliSearchClient $meiliSearchClient
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'The video ID to index');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $videoId = $input->getOption('id');
        
        if (!$videoId) {
            $io->error('Video ID is required.');
            return Command::FAILURE;
        }

        $video = $this->videoRepository->find($videoId);
        if (!$video) {
            $io->error("Video with ID $videoId not found.");
            return Command::FAILURE;
        }

        $data = $this->videoIndexer->transform($video);
        $io->note('JSON Payload:');
        $io->text(json_encode([$data], JSON_PRETTY_PRINT));

        try {
            $index = $this->meiliSearchClient->index('videos');
            $response = $index->addDocuments([$data]);
            $io->success("Sent to Meilisearch. Task ID: " . ($response['taskUid'] ?? 'N/A'));
        } catch (\Exception $e) {
            $io->error('Failed to send to Meilisearch: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
