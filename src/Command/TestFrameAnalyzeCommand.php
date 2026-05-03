<?php

namespace App\Command;

use App\Service\VideoAnalyzer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-frame-analyze',
    description: 'Add a short description for your command',
)]
class TestFrameAnalyzeCommand extends Command
{
    public function __construct(private readonly VideoAnalyzer $videoAnalyzer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->videoAnalyzer->analyzeFrame(
            1,
            '/home/wolfgang/Documents/programming/momentary/var/video-processing/video_c041937c/frames/frame_0005.jpg',
            20);

        return Command::SUCCESS;
    }
}
