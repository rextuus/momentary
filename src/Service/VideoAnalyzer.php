<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Person;
use App\Entity\Video;
use App\Entity\VideoFace;
use App\Message\FrameAnalyzerMessage;
use App\Repository\PersonRepository;
use App\Repository\VideoRepository;
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
            $process->setTimeout(300);
            $process->mustRun();

            $output = trim($process->getOutput());
            $frameList = null;
            if (preg_match('/\[\{.*\}\]/s', $output, $matches)) {
                $json = $matches[0];
                $frameList = json_decode($json, true);
            } else {
                echo "No JSON found.";
            }

            if ($frameList === null) {
                return false;
            }

            $lastKey = array_key_last($frameList);
            foreach ($frameList as $index => $frame) {
                $this->bus->dispatch(new FrameAnalyzerMessage(
                    $videoId,
                    $frame['path'],
                    $frame['timestamp'],
                    $index === $lastKey
                ));
            }

            return true;
        } catch (\Throwable $e) {
            dd($e);
            return false;
        }
    }

    public function analyzeFrame(int $videoId, string $framePath, int $timestamp): void
    {
        $process = new Process([
            $this->pythonBinary,
            'video-analyzer/python/analyze_frame.py',
            $framePath
        ]);
        $process->mustRun();

        $output = trim($process->getOutput());
        $results = json_decode($output, true);
        if (!is_array($results)) {
            throw new \RuntimeException('Invalid result from Python script.');
        }

        $video = $this->entityManager->getRepository(Video::class)->find($videoId);
        if (!$video) {
            throw new \RuntimeException("Video with ID {$videoId} not found.");
        }

        foreach ($results as $faceData) {
            $imageContent = file_get_contents($faceData['path']);
            $uuid = Uuid::uuid4();

            $storagePath = "video_faces/{$uuid}.jpg";

            $this->filesystem->write($storagePath, $imageContent);

            if (file_exists($faceData['path'])) {
                unlink($faceData['path']);
            }

            $videoFace = new VideoFace();
            $videoFace->setVideo($video);
            $videoFace->setTimestamp($timestamp);
            $videoFace->setFaceLabel($faceData['label']);
            $videoFace->setFaceImagePath($storagePath);
            $videoFace->setEmbedding($faceData['embedding']);
            $videoFace->setAge($faceData['age']);
            $videoFace->setGender($faceData['gender']);
            $videoFace->setEmotion($faceData['emotion']);

            $person = $this->personRepository->findOneBy(['name' => $faceData['label']]);
            if ($person === null){
                $latestUnknown = $this->personRepository->findBy(['identified' => false], ['id' => 'DESC'], 1);

                $unknownIndex = 0;
                if (count($latestUnknown) > 0){
                    $nameParts = explode('_', $latestUnknown[0]->getName());
                    $unknownIndex = (int) $nameParts[1];
                }

                $person = new Person();
                $person->setName('unknown_' . ($unknownIndex + 1));
                $person->setIdentified(false);
                $person->setDescription('Unknown person. Needs to be identified.');
                $person->addDetectionFace($videoFace);
                $videoFace->setDetection($person);

                $this->entityManager->persist($person);
            }

            $videoFace->setPerson($person);
            $person->addVideoFace($videoFace);
            if ((int) $faceData['matched_face_path'] >= 0) {
                $faceCausingMatch = $person->getDetectionFaces()->get((int) $faceData['matched_face_path']);
                $videoFace->setMatchedBy($faceCausingMatch);
                $videoFace->setMatchSimilarity($faceData['best_similarity']);
                $faceCausingMatch->addMatchFor($videoFace);
                $this->entityManager->persist($faceCausingMatch);
            }

            $this->entityManager->persist($videoFace);

            $this->entityManager->flush();
        }

        $this->entityManager->flush();
    }
}
