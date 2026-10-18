<?php

declare(strict_types=1);

namespace App\Command\Transcription;

use App\Entity\VideoTranscript;
use App\Enum\TranscriptionStatus;
use App\Service\Transcription\JellyfinSubtitleExporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Schreibt die Untertitel aller fertigen Transkripte (neu) nach Jellyfin, z. B. für Videos, die vor dem
 * Untertitel-Export transkribiert wurden.
 */
#[AsCommand(
    name: 'app:transcription:subtitles',
    description: 'Schreibt Untertitel (.srt) aller Transkripte zu den Jellyfin-Videos',
)]
final class ExportSubtitlesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly JellyfinSubtitleExporter $exporter,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $written = 0;
        $skipped = 0;

        foreach ($this->entityManager
            ->getRepository(VideoTranscript::class)
            ->findBy(['status' => TranscriptionStatus::DONE]) as $transcript) {
            $path = $this->exporter->export($transcript);
            if ($path === null) {
                $skipped++;
                continue;
            }
            $io->text(sprintf('Video %d: %s', (int) $transcript->getVideo()->getId(), $path));
            $written++;
        }

        $io->success(sprintf(
            '%d Untertitel geschrieben, %d Video(s) ohne Jellyfin-Export oder ohne Sprache übersprungen.',
            $written,
            $skipped,
        ));

        return Command::SUCCESS;
    }
}
