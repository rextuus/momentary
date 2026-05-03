<?php

namespace App\Command;

use App\Service\VideoAnalyzer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:test-analyze',
    description: 'Add a short description for your command',
)]
class TestAnalyzeCommand extends Command
{
    public function __construct(private readonly VideoAnalyzer $videoAnalyzer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('url', InputArgument::REQUIRED, 'The YouTube URL to analyze');
        $this->addArgument('video-id', InputArgument::OPTIONAL, 'Optional video ID to pass to analyzer (default: 999)', 999);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $url = $input->getArgument('url');
        $videoId = (int)$input->getArgument('video-id');

        $output->writeln("[🔍] Analyzing test video for URL: <info>$url</info>");

        $this->videoAnalyzer->downloadVideoAndSplitInFrames($videoId, $url);

        return Command::SUCCESS;
    }
}
