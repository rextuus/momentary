<?php

namespace App\Controller;

use App\Repository\VideoRepository;
use App\Entity\Video;
use App\Service\Storage\FileStorageService;
use App\Service\Storage\StoragePathProvider;
use App\Service\Video\VideoCreationService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

class UploadController extends AbstractController
{
    public function __construct(
        private readonly SluggerInterface $slugger,
        private readonly StoragePathProvider $pathProvider,
        private readonly FileStorageService $fileStorageService,
        private readonly VideoCreationService $videoCreationService,
        private readonly LoggerInterface $logger,
        private readonly VideoRepository $videoRepository,
    ) {}

    #[Route('/upload', name: 'app_upload', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $importRelativeDir = $this->pathProvider->getImportRelativePath();
        $importDir = $this->pathProvider->getImportAbsolutePath();

        if ($request->isMethod('POST')) {
            $videoFile = $request->files->get('video_file');

            if ($videoFile) {
                $originalFilename = pathinfo($videoFile->getClientOriginalName(), PATHINFO_FILENAME);
                
                // Hash berechnen
                $fileContent = file_get_contents($videoFile->getRealPath());
                $hash = hash('sha256', $fileContent);

                // Duplikatsprüfung: Prüfe nach Hash
                $existingVideo = $this->videoRepository->findOneBy(['fileHash' => $hash]);
                if ($existingVideo) {
                    $this->addFlash('warning', 'Dieses Video wurde bereits hochgeladen.');
                    return $this->redirectToRoute('app_video_show', ['id' => $existingVideo->getId()]);
                }

                $customFilename = $request->request->get('custom_filename');
                $safeFilename = $this->slugger->slug($customFilename ?: $originalFilename);
                $newFilename = $safeFilename . '-' . uniqid() . '.' . $videoFile->guessExtension();

                try {
                    $this->fileStorageService->createDirectoryStructure($importRelativeDir);

                    $videoFile->move(
                        $importDir,
                        $newFilename
                    );

                    $video = new Video();
                    $video->setTitle($originalFilename);
                    $video->setFileHash($hash);
                    $this->videoCreationService->createVideo($video, $newFilename, null, null, true);

                    $this->addFlash('success', 'Video "' . $originalFilename . '" erfolgreich hochgeladen.');

                    return $this->redirectToRoute('app_video_show', ['id' => $video->getId()]);
                } catch (FileException $e) {
                    $this->addFlash('danger', 'Fehler beim Upload: ' . $e->getMessage());
                } catch (\Exception $e) {
                    $this->addFlash('danger', 'Fehler bei der Videokreation: ' . $e->getMessage());
                }
            } else {
                $this->addFlash('warning', 'Bitte eine Datei auswählen.');
            }
        }

        return $this->render('upload/index.html.twig', [
            'importDir' => $importDir,
        ]);
    }
}