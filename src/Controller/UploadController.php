<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

class UploadController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/public/uploads/import')]
        private string $importDir,
        private SluggerInterface $slugger
    ) {}

    #[Route('/upload', name: 'app_upload', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $videoFile = $request->files->get('video_file');

            if ($videoFile) {
                $originalFilename = pathinfo($videoFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = $this->slugger->slug($originalFilename);
                $newFilename = $safeFilename . '-' . uniqid() . '.' . $videoFile->guessExtension();

                try {
                    if (!is_dir($this->importDir)) {
                        mkdir($this->importDir, 0777, true);
                    }

                    $videoFile->move(
                        $this->importDir,
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
            'importDir' => $this->importDir,
        ]);
    }
}
