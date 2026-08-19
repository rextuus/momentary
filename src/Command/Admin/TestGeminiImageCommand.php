<?php

declare(strict_types=1);

namespace App\Command\Admin;

use App\Service\Gemini\GeminiService;
use App\Service\VideoFileService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

#[AsCommand(
    name: 'app:gemini:test-image',
    description: 'Test Gemini image analysis with a specific image',
)]
class TestGeminiImageCommand extends Command
{
    public function __construct(
        private readonly GeminiService $geminiService,
        private readonly VideoFileService $videoFileService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $imagesDir = $this->videoFileService->getAbsolutePath('');

        $io->title('Test Gemini Image Analysis');

        if (!is_dir($imagesDir)) {
            $io->error('Images directory not found at ' . $imagesDir);
            return Command::FAILURE;
        }

        $finder = new Finder();
        $finder->files()->in($imagesDir)->name('*.jpg')->sortByName()->depth('<= 5');

        $choices = [];
        foreach ($finder as $file) {
            $choices[$file->getRealPath()] = $file->getRelativePathname();
        }

        if (empty($choices)) {
            $io->error('No images found in ' . $imagesDir);
            return Command::FAILURE;
        }

        $selectedPath = $io->choice('Select an image to analyze', $choices);

        $io->note('Analyzing: ' . $selectedPath);

        try {
            $result = $this->geminiService->analyzeImage($selectedPath);
            $io->success('Analysis completed.');
            $io->text(json_encode($result, JSON_PRETTY_PRINT));
        } catch (\Exception $e) {
            $io->error('Analysis failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}