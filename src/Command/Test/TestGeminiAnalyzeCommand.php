<?php

namespace App\Command\Test;

use App\Service\Gemini\GeminiService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test:gemini-analyze',
    description: 'Test Gemini image analysis',
)]
class TestGeminiAnalyzeCommand extends Command
{
    public function __construct(private readonly GeminiService $geminiService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('imagePath', InputArgument::REQUIRED, 'Path to the image');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $imagePath = $input->getArgument('imagePath');
        
        try {
            $tagsData = $this->geminiService->analyzeImage($imagePath);
            
            foreach ($tagsData as $category => $tags) {
                $io->section($category);
                $io->listing($tags);
            }
        } catch (\Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
