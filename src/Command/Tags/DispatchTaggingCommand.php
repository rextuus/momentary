<?php

declare(strict_types=1);

namespace App\Command\Tags;

use App\Repository\VideoRepository;
use App\Service\Video\Analyze\TaggingService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Taggt alle Szenen eines Videos neu, ohne die übrigen Pipeline-Schritte
 * (Kapitel, Benennung, Jellyfin-Export) erneut auszulösen.
 */
#[AsCommand(name: 'app:tags:dispatch', description: 'Tag all scenes of a video again')]
class DispatchTaggingCommand extends Command
{
    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly TaggingService $taggingService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('videoId', InputArgument::REQUIRED, 'The video ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $videoId = (int) $input->getArgument('videoId');

        $video = $this->videoRepository->find($videoId);
        if ($video === null) {
            $io->error(sprintf('Video %d not found.', $videoId));

            return Command::FAILURE;
        }

        $failed = 0;
        foreach ($io->progressIterate($video->getScenes()) as $scene) {
            $result = $this->taggingService->tagScene($scene);
            if (!$result->isSuccess()) {
                $failed++;
                $io->warning(sprintf('Scene %d: %s', $scene->getId(), $result->getMessage()));
            }
        }

        if ($failed > 0) {
            $io->error(sprintf('%d of %d scenes could not be tagged.', $failed, $video->getScenes()->count()));

            return Command::FAILURE;
        }

        $io->success(sprintf('Tagged %d scenes of video %d.', $video->getScenes()->count(), $videoId));

        return Command::SUCCESS;
    }
}
