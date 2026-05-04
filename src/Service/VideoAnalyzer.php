<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Person;
use App\Entity\VideoFace;
use App\Message\FrameAnalyzerMessage;
use App\Repository\PersonRepository;
use App\Repository\VideoRepository;
use App\Service\Aws\AmazonRekognitionService;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Ramsey\Uuid\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Process\Process;

readonly class VideoAnalyzer
{
    public function __construct(
        private MessageBusInterface $bus,
        private FilesystemOperator $filesystem,
        private EntityManagerInterface $entityManager,
        private VideoRepository $videoRepository,
        private PersonRepository $personRepository,
        private AmazonRekognitionService $rekognitionService,
        #[Autowire('%env(PYTHON_BINARY)%')]
        private string $pythonBinary = '/usr/bin/python3'
    ) {}

    public function downloadVideoAndSplitInFrames(int $videoId, string $youtubeUrl): bool
    {
        try {
            $process = new Process([
                $this->pythonBinary,
                'video-analyzer/python/download_and_extract.py',
                $youtubeUrl,
                '--video-id=' . $videoId
            ]);
            $process->setTimeout(600);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \RuntimeException('Python Script failed: ' . $process->getErrorOutput());
            }

            $output = trim($process->getOutput());
            $frameList = null;

            if (preg_match('/\[\s*\{.*\}\s*\]/s', $output, $matches)) {
                $frameList = json_decode($matches[0], true);
            }

            if ($frameList === null || empty($frameList)) {
                throw new \RuntimeException('Could not parse JSON from Python output.');
            }

            foreach ($frameList as $index => $frame) {
                $this->bus->dispatch(new FrameAnalyzerMessage(
                    $videoId,
                    $frame['path'],
                    (int) $frame['timestamp'],
                    $index === array_key_last($frameList)
                ));
            }

            return true;
        } catch (\Throwable $e) {
            dump($e->getMessage());
            return false;
        }
    }

    public function analyzeFrame(int $videoId, string $framePath, int $timestamp): void
    {
        // 1. Grundlegende Daten laden
        $video = $this->videoRepository->find($videoId);
        if (!$video) {
            return;
        }

        $faceData = $this->rekognitionService->processFaceImage($framePath);
        if (empty($faceData)) {
            return;
        }

        // 2. Bild speichern (Flysystem)
        $imageContent = file_get_contents($framePath);
        $uuid = Uuid::uuid4()->toString();
        $storagePath = "video_faces/{$uuid}.jpg";
        $this->filesystem->write($storagePath, $imageContent);

        // 3. Datenbank-Operationen
        try {
            // Wir verzichten auf wrapInTransaction, um SQLite nicht zu blockieren,
            // nutzen aber manuelle Flush-Kontrolle.

            $person = null;
            $matchedFace = null;

            // Suche nach existierendem Gesicht (Face Matching)
            if (!empty($faceData['matchedFaceId'])) {
                $matchedFace = $this->entityManager->getRepository(VideoFace::class)
                    ->findOneBy(['faceLabel' => $faceData['matchedFaceId']]);

                if ($matchedFace && $matchedFace->getPerson()) {
                    $person = $matchedFace->getPerson();
                }
            }

            // Falls keine Person gefunden wurde, neue anlegen
            if ($person === null) {
                $person = new Person();
                $person->setName('unknown_' . substr($faceData['faceId'], 0, 8));
                $person->setIdentified(false);

                $this->entityManager->persist($person);
                // WICHTIG: Sofort flashen, damit die ID für SQLite generiert wird
                $this->entityManager->flush();
            }

            // 4. VideoFace erstellen
            $videoFace = new VideoFace();
            $videoFace->setVideo($video);
            $videoFace->setPerson($person); // Die Verknüpfung zur Person
            $videoFace->setTimestamp($timestamp);
            $videoFace->setFaceImagePath($storagePath);
            $videoFace->setFaceLabel($faceData['faceId']);

            // Mapping der numerischen/string Werte (sicherstellen, dass sie valide sind)
            $videoFace->setAge((int)($faceData['age'] ?? 0));
            $videoFace->setGender((string)($faceData['gender'] ?? 'unknown'));
            $videoFace->setEmotion((string)($faceData['emotion'] ?? 'unknown'));

            if ($matchedFace) {
                $videoFace->setMatchedBy($matchedFace);
                $videoFace->setMatchSimilarity((float)($faceData['similarity'] ?? 0));
            }

            $this->entityManager->persist($videoFace);
            // Finaler Flush für das VideoFace
            $this->entityManager->flush();

        } catch (\Exception $e) {
            // Im Fehlerfall EntityManager leeren, damit beim nächsten Retry kein Müll drin steht
            $this->entityManager->clear();
            throw $e;
        }
    }
}