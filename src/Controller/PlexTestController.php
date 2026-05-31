<?php

namespace App\Controller;

use App\Service\PlexUploadService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PlexTestController extends AbstractController
{
    #[Route('/plex/test-upload', name: 'app_plex_test_upload')]
    public function testUpload(PlexUploadService $plexUploadService): Response
    {
        // For testing purposes, we might look for a file in a specific temp location
        // or just provide instructions.
        return $this->render('plex_test/index.html.twig', [
            'upload_dir' => $this->getParameter('kernel.project_dir') . '/docker/plex/uploads',
        ]);
    }

    #[Route('/plex/trigger-scan', name: 'app_plex_trigger_scan')]
    public function triggerScan(PlexUploadService $plexUploadService): Response
    {
        $plexUploadService->triggerScan();
        $this->addFlash('success', 'Plex library scan triggered.');
        return $this->redirectToRoute('app_plex_test_upload');
    }
}
