<?php

declare(strict_types=1);

namespace App\Command\Photo;

use App\Entity\Photo;
use App\Service\Photo\PhotoBursts;
use App\Service\Photo\PhotoProcessor;
use App\Service\Storage\StoragePathProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:photo:bursts', description: 'Berechnet fehlende Fingerabdrücke und bildet die Serienfotos neu')]
final class PhotoBurstsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PhotoProcessor $processor,
        private readonly StoragePathProvider $pathProvider,
        private readonly PhotoBursts $bursts,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $missing = $this->entityManager->getRepository(Photo::class)->findBy(['fingerprint' => null]);
        $measured = 0;
        foreach ($io->progressIterate($missing) as $photo) {
            $file = $photo->getDisplayFile();
            $path = $file !== null ? $this->pathProvider->getAbsolutePath($file) : null;
            if ($path === null || !is_file($path)) {
                continue;
            }
            try {
                $metrics = $this->processor->measure($path);
                $photo->setImageMetrics($metrics['fingerprint'], $metrics['sharpness']);
                $measured++;
            } catch (\RuntimeException $e) {
                $io->warning(sprintf('Foto %d: %s', (int) $photo->getId(), $e->getMessage()));
            }
        }
        $this->entityManager->flush();

        $count = $this->bursts->regroupAll();
        $io->success(sprintf('%d Fingerabdruck/-drücke berechnet, %d Serie(n) gefunden.', $measured, $count));

        return Command::SUCCESS;
    }
}
