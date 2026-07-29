<?php

namespace App\Controller;

use App\Service\ImageFileService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

class ImageController extends AbstractController
{
    public function __construct(
        private readonly ImageFileService $imageFileService
    ) {}

    #[Route('/display-face/{path}', name: 'display_face', requirements: ['path' => '.+'])]
    public function showFace(string $path): Response
    {
        $filesystem = $this->imageFileService->getFilesystem();
        if (!$filesystem->has($path)) {
            throw $this->createNotFoundException('Image not found.');
        }

        return new StreamedResponse(function () use ($path, $filesystem) {
            $outputStream = fopen('php://output', 'wb');
            $fileStream = $filesystem->readStream($path);
            stream_copy_to_stream($fileStream, $outputStream);
        }, 200, [
            'Content-Type' => 'image/jpeg', // Oder dynamisch via Flysystem mimeType()
        ]);
    }
}