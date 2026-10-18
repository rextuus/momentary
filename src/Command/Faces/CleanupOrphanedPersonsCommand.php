<?php

declare(strict_types=1);

namespace App\Command\Faces;

use App\Service\Face\OrphanedPersonCleaner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:persons:cleanup-orphans',
    description: 'Entfernt automatisch angelegte Personen (Status NEW) ohne ein einziges Gesicht',
)]
final class CleanupOrphanedPersonsCommand extends Command
{
    public function __construct(
        private readonly OrphanedPersonCleaner $cleaner,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $removed = $this->cleaner->removeOrphans();
        new SymfonyStyle($input, $output)->success(sprintf('%d leere Person(en) entfernt.', $removed));

        return Command::SUCCESS;
    }
}
