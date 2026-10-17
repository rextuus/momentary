<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\VideoStatus;
use App\Repository\VideoRepository;
use App\Service\Storage\VideoOriginalCleanupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:video:cleanup-originals',
    description: 'Räumt Speicher auf: Originale und Frames fertiger Videos sowie verwaiste Ordner.',
)]
class CleanupOriginalsCommand extends Command
{
    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly VideoOriginalCleanupService $cleanupService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur anzeigen, was freigegeben würde.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $verb = $dryRun ? 'Würde freigeben' : 'Freigegeben';

        // 1. Fertige Videos: Original + Frames
        $count = 0;
        $totalBytes = 0;
        foreach ($this->videoRepository->findBy(['status' => VideoStatus::COMPLETED]) as $video) {
            $bytes = $this->cleanupService->cleanupCompleted($video, $dryRun);
            if ($bytes > 0) {
                $count++;
                $totalBytes += $bytes;
                $io->writeln(sprintf('  #%d %s: %s', $video->getId(), $video->getTitle(), $this->format($bytes)));
            }
        }
        $io->writeln(sprintf('%s (fertige Videos): %s in %d Video(s)', $verb, $this->format($totalBytes), $count));

        // 2. Verwaiste Ordner ohne zugehöriges Video
        $existingIds = $this->videoRepository
            ->createQueryBuilder('v')
            ->select('v.id')
            ->getQuery()
            ->getSingleColumnResult();

        $orphans = $this->cleanupService->cleanupOrphanDirectories($existingIds, $dryRun);
        $orphanBytes = array_sum($orphans);
        foreach ($orphans as $path => $bytes) {
            $io->writeln(sprintf('  %s: %s', $path, $this->format($bytes)));
        }
        $io->writeln(sprintf(
            '%s (verwaiste Ordner): %s in %d Ordner(n)',
            $verb,
            $this->format($orphanBytes),
            count($orphans),
        ));

        $io->success(sprintf('%s insgesamt: %s', $verb, $this->format($totalBytes + $orphanBytes)));

        return Command::SUCCESS;
    }

    private function format(int|float $bytes): string
    {
        return $bytes >= 1073741824 ? round($bytes / 1073741824, 2) . ' GB' : round($bytes / 1048576, 1) . ' MB';
    }
}
