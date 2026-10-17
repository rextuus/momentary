<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Video;
use App\Enum\VideoStatus;
use App\Repository\VideoRepository;
use App\Service\Storage\StoragePathProvider;
use App\Service\Video\Queue\ImportQueueManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/video/import')]
final class VideoImportController extends AbstractController
{
    public function __construct(
        private readonly StoragePathProvider $pathProvider,
        private readonly VideoRepository $videoRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ImportQueueManager $importQueueManager,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/', name: 'admin_video_import', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $importDir = $this->pathProvider->getImportAbsolutePath();
        $files = [];

        if (is_dir($importDir)) {
            $foundFiles = scandir($importDir);
            $allowedExtensions = ['mp4', 'mov', 'avi', 'mkv', 'webm'];

            foreach ($foundFiles as $file) {
                if ($file === '.' || $file === '..' || is_dir($importDir . DIRECTORY_SEPARATOR . $file)) {
                    continue;
                }

                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExtensions, true)) {
                    continue;
                }

                $alreadyExists = $this->videoRepository->findOneBy(['title' => $file]);

                $files[] = [
                    'name' => $file,
                    'exists' => (bool) $alreadyExists,
                    'size' => filesize($importDir . DIRECTORY_SEPARATOR . $file),
                ];
            }
        }

        if ($request->isMethod('POST')) {
            $selectedFiles = $request->request->all('files');
            $importedCount = 0;

            foreach ($selectedFiles as $filename) {
                if ($this->videoRepository->findOneBy(['title' => $filename])) {
                    continue;
                }

                $video = new Video();
                $video->setTitle($filename);
                $video->setStatus(VideoStatus::QUEUED);
                $video->setCreatedAt(new \DateTimeImmutable());

                $this->entityManager->persist($video);
                $importedCount++;
            }

            $this->entityManager->flush();

            if ($importedCount > 0) {
                $this->logger->info(sprintf('Imported %d videos, processing queue.', $importedCount));
                $this->importQueueManager->processQueue();
                $this->addFlash('success', sprintf(
                    '%d Video(s) wurden in die Warteschlange aufgenommen.',
                    $importedCount,
                ));
            }

            return $this->redirectToRoute('admin_video_import');
        }

        return $this->render('admin/video/import.html.twig', [
            'files' => $files,
        ]);
    }
}
