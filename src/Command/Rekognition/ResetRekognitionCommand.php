<?php

namespace App\Command\Rekognition;

use App\Service\Aws\AmazonRekognitionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:rekognition:reset',
    description: 'Löscht und erstellt die Amazon Rekognition Collection neu',
)]
class ResetRekognitionCommand extends Command
{
    public function __construct(
        private AmazonRekognitionService $rekognitionService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $collectionId = $this->rekognitionService->getCollectionId();

        if (!$io->confirm("Bist du sicher, dass du die AWS Collection '{$collectionId}' komplett zurücksetzen möchtest? Alle indizierten Gesichter gehen verloren.")) {
            return Command::SUCCESS;
        }

        try {
            $io->info("Setze Collection '{$collectionId}' zurück...");
            $this->rekognitionService->resetCollection();
            $io->success("Collection '{$collectionId}' wurde erfolgreich zurückgesetzt.");
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error("AWS Fehler: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
