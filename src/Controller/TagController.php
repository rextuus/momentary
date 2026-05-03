<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/tagging')]
final class TagController extends AbstractController
{

    #[Route('/tag', name: 'app_tag')]
    public function tag(HttpClientInterface $httpClient): Response
    {
        $imagePath = __DIR__ . '/../../assets/images/test_face.jpg';
        $imageUrl = '/../../assets/images/test_face.jpg';

        // 1. Call /analyze
        $analyzeResponse = $httpClient->request('POST', 'http://localhost:5000/analyze', [
            'body' => ['image' => fopen($imagePath, 'r')]
        ]);
        $analyzeData = $analyzeResponse->toArray();

        // 2. Call /identify
        $identifyResponse = $httpClient->request('POST', 'http://localhost:5000/analyze', [
            'body' => ['image' => fopen($imagePath, 'r')]
        ]);
        $identifyData = $identifyResponse->toArray();

        // 3. Merge both responses by face index
        $faces = [];
        foreach ($analyzeData as $index => $face) {
            $box = $face['region'];
            $match = $identifyData[$index]['best_match'] ?? null;

            $faces[] = [
                'x' => $box['x'],
                'y' => $box['y'],
                'w' => $box['w'],
                'h' => $box['h'],
                'age' => $face['age'],
                'emotion' => $face['dominant_emotion'],
                'gender' => $face['dominant_gender'],
                'name' => $match['identity'] ?? null,
                'confidence' => $match['distance'] ?? null
            ];
        }

        return $this->render('face_test/index.html.twig', [
            'imageUrl' => $imageUrl,
            'faces' => $faces
        ]);
    }
}
