<?php

namespace App\Command\Video;

use App\Repository\VideoRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:video:list-scenes',
    description: 'Lists all scenes and chapters for a given video ID',
)]
class ListVideoScenesCommand extends Command
{
    public function __construct(
        private readonly VideoRepository $videoRepository,
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
        $videoId = (int)$input->getArgument('videoId');

        $video = $this->videoRepository->find($videoId);
        if (!$video) {
            $io->error("Video $videoId not found.");
            return Command::FAILURE;
        }

        $io->title("Video #$videoId – Status: " . $video->getStatus()->value);

        $scenes = $video->getScenes();
        $chapters = $video->getChapters();

        $io->section("Scenes (" . count($scenes) . ")");
        if ($scenes->isEmpty()) {
            $io->text('No scenes.');
        } else {
            $rows = [];
            foreach ($scenes as $scene) {
                $tags = array_map(fn($t) => $t->getName(), $scene->getTags()->toArray());
                
                // Prüfen, ob die Szene in irgendein Kapitel fällt
                $matchingChapters = [];
                foreach ($chapters as $chapter) {
                    if ($scene->getStartSeconds() >= $chapter->getStartSeconds() && $scene->getEndSeconds() <= $chapter->getEndSeconds()) {
                        $matchingChapters[] = $chapter->getId();
                    }
                }
                
                $rows[] = [
                    $scene->getId(),
                    $scene->getSceneNumber(),
                    $scene->getStartSeconds(),
                    $scene->getEndSeconds(),
                    $scene->getTitle() ?? '–',
                    implode(', ', $tags),
                    empty($matchingChapters) ? 'KEINE ZUORDNUNG' : implode(', ', $matchingChapters),
                ];
            }
            $io->table(['ID', '#', 'Start', 'End', 'Title', 'Tags', 'Kapitel-IDs'], $rows);
        }

        $io->section("Chapters (" . count($chapters) . ")");
        if ($chapters->isEmpty()) {
            $io->text('No chapters.');
        } else {
            $rows = [];
            foreach ($chapters as $chapter) {
                $rows[] = [
                    $chapter->getId(),
                    $chapter->getTitle(),
                    $chapter->getStartSeconds(),
                    $chapter->getEndSeconds(),
                    $chapter->getDescription() ?? '–',
                ];
            }
            $io->table(['ID', 'Title', 'Start', 'End', 'Description'], $rows);
        }

        return Command::SUCCESS;
    }
}
