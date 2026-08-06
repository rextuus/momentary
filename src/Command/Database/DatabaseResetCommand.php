<?php

namespace App\Command\Database;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:database:reset',
    description: 'Leert die Datenbank, außer der User-Tabelle',
)]
class DatabaseResetCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        if (!$io->confirm('Sind Sie sicher, dass Sie die gesamte Datenbank außer der User-Tabelle leeren möchten?', false)) {
            $io->warning('Abgebrochen.');
            return Command::FAILURE;
        }

        $connection = $this->entityManager->getConnection();
        $schemaManager = $connection->createSchemaManager();
        $tables = $schemaManager->listTableNames();

        $connection->beginTransaction();
        try {
            // Deaktivieren der Foreign-Key-Checks (Syntax ist DB-abhängig, meist MySQL/MariaDB/PostgreSQL)
            // Hier gehe ich von MySQL aus, da "public/uploads" vorkam (typisch für viele kleine PHP Projekte)
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

            foreach ($tables as $table) {
                if ($table === 'user') {
                    continue;
                }
                
                $io->note(sprintf('Leere Tabelle: %s', $table));
                $connection->executeStatement(sprintf('TRUNCATE TABLE %s', $table));
            }

            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            $connection->commit();
            $io->success('Datenbank wurde erfolgreich geleert.');
        } catch (\Exception $e) {
            $connection->rollBack();
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            $io->error('Ein Fehler ist aufgetreten: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
