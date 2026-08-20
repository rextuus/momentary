<?php

namespace App\Command\Database;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
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
        
        if (!$io->confirm('Sind Sie sicher, dass Sie die gesamte Datenbank außer der User-Tabelle leeren möchten?', true)) {
            $io->warning('Abgebrochen.');
            return Command::FAILURE;
        }

        $connection = $this->entityManager->getConnection();
        
        try {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            
            $schemaManager = $connection->createSchemaManager();
            $tables = $schemaManager->listTableNames();

            foreach ($tables as $table) {
                if ($table === 'user') {
                    continue;
                }
                
                $io->note(sprintf('Leere Tabelle: %s', $table));
                $connection->executeStatement(sprintf('TRUNCATE TABLE %s', $table));
            }
            
            $io->success('Datenbank wurde erfolgreich geleert.');
        } catch (\Exception $e) {
            $io->error('Ein Fehler ist aufgetreten: ' . $e->getMessage());
            return Command::FAILURE;
        } finally {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            $this->entityManager->clear();
        }

        $io->note('Führe doctrine:schema:update --force aus...');
        $command = $this->getApplication()->find('doctrine:schema:update');
        $result = $command->run(new ArrayInput(['--force' => true]), $output);
        
        if ($result !== Command::SUCCESS) {
            $io->error('Fehler beim Aktualisieren des Datenbankschemas.');
            return $result;
        }

        $io->success('Datenbank erfolgreich geleert und Schema aktualisiert.');

        return Command::SUCCESS;
    }
}
