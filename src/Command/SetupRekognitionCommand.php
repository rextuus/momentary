<?php

namespace App\Command;

use App\Service\Aws\AmazonRekognitionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:rekognition:setup',
    description: 'Erstellt die Amazon Rekognition Collection für das Projekt',
)]
class SetupRekognitionCommand extends Command
{
    public function __construct(
        private AmazonRekognitionService $rekognitionService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $client = $this->rekognitionService->getClient();
        $collectionId = $this->rekognitionService->getCollectionId();

        try {
            $io->info("Prüfe Collection '{$collectionId}'...");

            $collections = $client->listCollections();
            $ids = $collections['CollectionIds'] ?? [];

            if (in_array($collectionId, $ids)) {
                $io->success("Die Collection existiert bereits!");
                return Command::SUCCESS;
            }

            $client->createCollection(['CollectionId' => $collectionId]);

            $io->success("Collection '{$collectionId}' wurde erfolgreich erstellt.");
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error("AWS Fehler: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
