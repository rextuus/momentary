<?php

namespace App\Command\Search;

use Meilisearch\Client as MeiliSearchClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:search:test',
    description: 'Tests Meilisearch connectivity and search functionality',
)]
class TestSearchCommand extends Command
{
    public function __construct(
        private readonly MeiliSearchClient $meiliSearchClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('query', InputArgument::OPTIONAL, 'The search query', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $query = $input->getArgument('query');

        $io->note(sprintf('Searching for: "%s"', $query));

        try {
            $index = $this->meiliSearchClient->index('videos');
            $searchResult = $index->search($query ?: null);
            
            $hits = $searchResult->getHits();
            $io->success(sprintf('Found %d hits', count($hits)));

            foreach ($hits as $hit) {
                $io->text(sprintf('- ID: %s, Title: %s', $hit['id'] ?? '?', $hit['title'] ?? '?'));
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Search failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
