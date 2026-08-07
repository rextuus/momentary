<?php

namespace App\Command\Admin;

use App\Service\Gemini\GeminiService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:gemini:test',
    description: 'Test Gemini connection and status',
)]
class TestGeminiConnectionCommand extends Command
{
    public function __construct(
        private readonly GeminiService $geminiService,
        #[Autowire('%env(GEMINI_API_KEY)%')]
        private readonly string $apiKey,
        #[Autowire('%env(bool:ENABLE_TAGGING_SCENES)%')]
        private readonly bool $enableTagging,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Gemini Connection Test');

        $io->section('Configuration');
        $io->text('Tagging enabled: ' . ($this->enableTagging ? 'Yes' : 'No'));
        $io->text('API Key present: ' . (!empty($this->apiKey) ? 'Yes (length: ' . strlen($this->apiKey) . ')' : 'No'));

        if (!$this->enableTagging) {
            $io->warning('Tagging is disabled in configuration.');
        }

        if (empty($this->apiKey)) {
            $io->error('API Key is missing.');
            return Command::FAILURE;
        }

        $io->section('Testing Connection');
        try {
            $io->text('Sending test request to Gemini...');
            // Try suggesting chapters with minimal test data
            $testData = [
                ['title' => 'Test Szene', 'tags' => ['Test', 'Example']]
            ];
            $result = $this->geminiService->suggestChapters($testData);
            $io->success('Connection successful! Gemini responded.');
            $io->text('Response: ' . json_encode($result, JSON_PRETTY_PRINT));
        } catch (\Exception $e) {
            $io->error('Connection failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
