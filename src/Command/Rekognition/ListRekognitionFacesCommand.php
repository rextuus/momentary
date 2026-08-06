<?php

namespace App\Command\Rekognition;

use App\Service\Aws\AmazonRekognitionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:rekognition:list-faces',
    description: 'Listet alle indizierten Gesichter in der AWS Rekognition Collection auf',
)]
class ListRekognitionFacesCommand extends Command
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

        $io->title("Gesichter in Collection: {$collectionId}");

        try {
            $faces = $this->rekognitionService->listFaces();
            
            if (empty($faces)) {
                $io->warning("Die Collection ist leer.");
                return Command::SUCCESS;
            }

            $io->table(
                ['FaceId', 'ImageId', 'ExternalImageId', 'Confidence'],
                array_map(fn($face) => [
                    $face['FaceId'],
                    $face['ImageId'],
                    $face['ExternalImageId'] ?? 'N/A',
                    $face['Confidence']
                ], $faces)
            );

            $io->success(sprintf("Es wurden %d Gesichter gefunden.", count($faces)));
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error("AWS Fehler: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
