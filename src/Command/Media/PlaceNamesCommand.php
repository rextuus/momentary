<?php

declare(strict_types=1);

namespace App\Command\Media;

use App\Entity\Medium;
use App\Service\Geo\PlaceTagger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ermittelt Ortsnamen für Medien mit GPS-Position, die noch keinen haben (z. B. nach dem Einschalten des Geocodings).
 */
#[AsCommand(name: 'app:media:places', description: 'Ergänzt Ortsnamen und Orts-Tags für Medien mit GPS-Position')]
final class PlaceNamesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlaceTagger $placeTagger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var list<Medium> $media */
        $media = $this->entityManager
            ->createQueryBuilder()
            ->select('m')
            ->from(Medium::class, 'm')
            ->where('m.latitude IS NOT NULL AND m.longitude IS NOT NULL AND m.placeName IS NULL')
            ->getQuery()
            ->getResult();

        $found = 0;
        foreach ($io->progressIterate($media) as $medium) {
            // höchstens eine Anfrage pro Sekunde (Nominatim-Regeln), gleiche Orte kommen aus dem Cache
            if ($this->placeTagger->tag($medium)) {
                $found++;
            }
        }
        $this->entityManager->flush();

        $io->success(sprintf('%d von %d Medien haben einen Ortsnamen bekommen.', $found, \count($media)));

        return Command::SUCCESS;
    }
}
