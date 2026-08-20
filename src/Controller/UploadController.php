<?php

namespace App\Controller;

use App\Service\Storage\FileStorageService;
use App\Service\Storage\StoragePathProvider;
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
    ) {}

    #[Route('/upload', name: 'app_upload', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $importRelativeDir = $this->pathProvider->getImportRelativePath();
        $importDir = $this->pathProvider->getImportAbsolutePath();

        if ($request->isMethod('POST')) {
            $videoFile = $request->files->get('video_file');

            if ($videoFile) {
                $customFilename = $request->request->get('custom_filename');
                $originalFilename = $customFilename ?: pathinfo($videoFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = $this->slugger->slug($originalFilename);
                $newFilename = $safeFilename . '-' . uniqid() . '.' . $videoFile->guessExtension();

                try {
                    $this->fileStorageService->createDirectoryStructure($importRelativeDir);

                    $videoFile->move(
                        $importDir,
                        $newFilename
                    );

                    $this->addFlash('success', 'Video "' . $newFilename . '" erfolgreich hochgeladen.');

                    if ($request->request->has('redirect_to_new')) {
                        return $this->redirectToRoute('video_new');
                    }
                } catch (FileException $e) {
                    $this->addFlash('danger', 'Fehler beim Upload: ' . $e->getMessage());
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