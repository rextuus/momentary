<?php

namespace App\Command\Meilisearch;

use Meilisearch\Client as MeiliSearchClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:meilisearch:list-indexes',
    description: 'Lists all MeiliSearch indexes and shows their content',
)]
class ListIndexesCommand extends Command
{
    public function __construct(
        private MeiliSearchClient $meiliSearchClient
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('show-documents', null, InputOption::VALUE_NONE, 'Show documents in the indexes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $showDocuments = $input->getOption('show-documents');

        try {
            $indexes = $this->meiliSearchClient->getIndexes();
        } catch (\Exception $e) {
            $io->error('Failed to fetch indexes: ' . $e->getMessage());
            return Command::FAILURE;
        }

        if (empty($indexes)) {
            $io->success('No indexes found.');
            return Command::SUCCESS;
        }

        $io->title('MeiliSearch Indexes');

        foreach ($indexes as $index) {
            $io->section(sprintf('Index: %s', $index->getUid()));
            $io->text(sprintf('Primary Key: %s', $index->getPrimaryKey() ?? 'Not set'));
            $io->text(sprintf('Created at: %s', $index->getCreatedAt()?->format('Y-m-d H:i:s') ?? 'N/A'));
            $io->text(sprintf('Updated at: %s', $index->getUpdatedAt()?->format('Y-m-d H:i:s') ?? 'N/A'));

            if ($showDocuments) {
                $io->text('Documents (first 5):');
                $documents = $index->getDocuments(['limit' => 5]);
                
                if (empty($documents)) {
                    $io->text('No documents found.');
                } else {
                    if ($index->getUid() === 'videos') {
                        $tableRows = [];
                        foreach ($documents as $doc) {
                            $tableRows[] = [
                                $doc['id'] ?? 'N/A',
                                $doc['title'] ?? 'N/A',
                                implode(', ', $doc['tags'] ?? []),
                                implode(', ', $doc['persons'] ?? []),
                            ];
                        }
                        $io->table(['ID', 'Title', 'Tags', 'Persons'], $tableRows);
                    } else {
                        $tableRows = [];
                        foreach ($documents as $doc) {
                            $tableRows[] = [json_encode($doc)];
                        }
                        $io->table(['Document'], $tableRows);
                    }
                }
            }
            $io->newLine();
        }

        return Command::SUCCESS;
    }
}
