<?php

declare(strict_types=1);

namespace App\Service\Gemini;

use Gemini;
use Gemini\Client;
use Gemini\Data\Blob;
use Gemini\Data\GenerationConfig;
use Gemini\Data\ImageConfig;
use Gemini\Enums\MimeType;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class GeminiService
{
    private Client $client;

    public function __construct(
        #[Autowire('%env(GEMINI_API_KEY)%')]
        private readonly string $apiKey,
    ) {
        $this->client = Gemini::client($this->apiKey);
    }

    public function generateImage(string $imageUrl, string $prompt): string
    {
        $imageConfig = new ImageConfig(aspectRatio: '3:4');
        $generationConfig = new GenerationConfig(imageConfig: $imageConfig);

        $response = $this->client
            ->generativeModel(model: 'gemini-3.1-flash-image')
            ->withGenerationConfig($generationConfig)
            ->generateContent([
                $prompt,
                new Blob(
                    mimeType: MimeType::IMAGE_JPEG,
                    data: base64_encode(
                        file_get_contents($imageUrl)
                    )
                )
            ]);

        foreach ($response->parts() as $part) {
            if ($part->inlineData !== null) {
                return base64_decode($part->inlineData->data);
            }
        }

        throw new RuntimeException('Gemini did not return an image in the response. Check safety ratings or response content.');
    }

    public function analyzeImage(string $imagePath): array
    {
        $prompt = 'Analysiere dieses Bild und gib mir eine Liste von Tags zurück, die beschreiben, was auf dem Bild passiert. 
            Gruppiere die Tags in sinnvolle Kategorien.
            Antworte ausschließlich im JSON-Format, z.B. 
            {
                "Personen": ["Kind", "Mann"],
                "Aktivitäten": ["Sport", "Essen"],
                "Orte": ["Küche", "Park"]
            }';

        $response = $this->client
            ->generativeModel(model: 'gemini-3.1-flash-lite')
            ->generateContent([
                $prompt,
                new Blob(
                    mimeType: MimeType::IMAGE_JPEG,
                    data: base64_encode(
                        file_get_contents($imagePath)
                    )
                )
            ]);

        $text = $response->text();
        $text = str_replace(['```json', '```'], '', $text);
        
        return json_decode(trim($text), true) ?? [];
    }

    public function suggestChapters(array $scenesData): array
    {
        $prompt = 'Hier ist eine Übersicht über alle Szenen eines Videos mit ihren zugeordneten Tags/Kategorien: ' . json_encode($scenesData) . '.
            Bitte schlage basierend auf diesen Informationen sinnvolle Kapitel vor, um das Video zu strukturieren.
            Jedes Kapitel sollte einen Titel, einen Start-Zeitpunkt, einen End-Zeitpunkt und eine kurze Beschreibung haben.
            Antworte ausschließlich im JSON-Format, z.B. 
            [
                {
                    "title": "Kapitel 1",
                    "startSeconds": 0,
                    "endSeconds": 30,
                    "description": "Beschreibung"
                }
            ]';

        $response = $this->client
            ->generativeModel(model: 'gemini-3.1-flash-lite')
            ->generateContent([
                $prompt
            ]);

        $text = $response->text();
        $text = str_replace(['```json', '```'], '', $text);
        
        return json_decode(trim($text), true) ?? [];
    }
}

