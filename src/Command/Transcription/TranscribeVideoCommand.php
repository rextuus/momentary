<?php

declare(strict_types=1);

namespace App\Command\Transcription;

use App\Entity\Video;
use App\Enum\TranscriptionStatus;
use App\Enum\VideoStatus;
use App\Repository\VideoRepository;
use App\Service\Transcription\TranscriptionRequester;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:video:transcribe',
    description: 'Plant die Ton-Transkription von Videos ein (läuft im transcription-worker)',
)]
final class TranscribeVideoCommand extends Command
{
    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly TranscriptionRequester $requester,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('videoIds', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'Video-IDs')->addOption(
            'missing',
            null,
            InputOption::VALUE_NONE,
            'Alle fertig analysierten Videos ohne Transkript',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('missing')) {
            $videos = array_filter(
                $this->videoRepository->findBy(['status' => VideoStatus::COMPLETED]),
                static fn(Video $video): bool => $video->getTranscript()?->getStatus() !== TranscriptionStatus::DONE,
            );
        } else {
            $videos = [];
            foreach ((array) $input->getArgument('videoIds') as $videoId) {
                $video = is_numeric($videoId) ? $this->videoRepository->find((int) $videoId) : null;
                if ($video === null) {
                    $io->warning(sprintf('Video %s nicht gefunden.', $videoId));
                    continue;
                }
                $videos[] = $video;
            }
        }

        if ($videos === []) {
            $io->note('Keine Videos angegeben. Video-IDs nennen oder --missing verwenden.');

            return Command::SUCCESS;
        }

        $queued = 0;
        foreach ($videos as $video) {
            $reason = $this->requester->whyNot($video);
            if ($reason !== null) {
                $io->warning(sprintf('Video %d: %s', (int) $video->getId(), $reason));
                continue;
            }
            $this->requester->request($video);
            $queued++;
        }

        $io->success(sprintf('%d Video(s) zur Transkription eingeplant.', $queued));

        return Command::SUCCESS;
    }
}
