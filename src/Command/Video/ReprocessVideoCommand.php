<?php

declare(strict_types=1);

namespace App\Command\Video;

use App\Repository\VideoRepository;
use App\Service\Video\VideoReprocessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Stellt (fehlgeschlagene oder fertige) Videos erneut in die Verarbeitungs-Queue.
 */
#[AsCommand(name: 'app:video:reprocess', description: 'Startet die Verarbeitung von Videos von vorne')]
final class ReprocessVideoCommand extends Command
{
    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly VideoReprocessor $reprocessor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('videoIds', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Video-IDs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $videos = [];
        foreach ((array) $input->getArgument('videoIds') as $videoId) {
            $video = is_numeric($videoId) ? $this->videoRepository->find((int) $videoId) : null;
            if ($video === null) {
                $io->warning(sprintf('Video %s nicht gefunden.', $videoId));
                continue;
            }
            $reason = $this->reprocessor->whyNot($video);
            if ($reason !== null) {
                $io->warning(sprintf('Video %d: %s', $video->getId(), $reason));
                continue;
            }
            $videos[] = $video;
        }

        $result = $this->reprocessor->reprocess($videos);
        $io->success(sprintf(
            '%d Video(s) eingereiht, %d davon sofort gestartet.',
            $result['queued'],
            $result['started'],
        ));

        return Command::SUCCESS;
    }
}
