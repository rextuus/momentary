<?php

namespace App\Controller;

use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;

class ImageController extends AbstractController
{
    public function __construct(
        private readonly FilesystemOperator $facesStorage
    ) {}

    #[Route('/display-face/{path}', name: 'display_face', requirements: ['path' => '.+'])]
    public function showFace(string $path): Response
    {
        if (!$this->facesStorage->has($path)) {
            throw $this->createNotFoundException('Image not found.');
        }

        return new StreamedResponse(function () use ($path) {
            $outputStream = fopen('php://output', 'wb');
            $fileStream = $this->facesStorage->readStream($path);
            stream_copy_to_stream($fileStream, $outputStream);
        }, 200, [
            'Content-Type' => 'image/jpeg', // Oder dynamisch via Flysystem mimeType()
        ]);
    }
}