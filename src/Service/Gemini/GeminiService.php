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
            Zusätzlich schlage einen prägnanten Titel für diese Szene vor.
            Antworte ausschließlich im JSON-Format, z.B. 
            {
                "Titel": "Ein toller Titel",
                "Tags": {
                    "Personen": ["Kind", "Mann"],
                    "Aktivitäten": ["Sport", "Essen"],
                    "Orte": ["Küche", "Park"]
                }
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
        fwrite(STDOUT, "[Gemini] Raw response: " . $text . PHP_EOL);
        $text = str_replace(['```json', '```'], '', $text);
        
        $decoded = json_decode(trim($text), true);
        if ($decoded === null) {
            fwrite(STDOUT, "[Gemini] JSON decode failed for: " . $text . PHP_EOL);
        }
        return $decoded ?? [];
    }

    public function suggestChapters(array $scenesData): array
    {
        // Shortcut: Wenn nur eine Szene vorhanden ist, direkt ein Kapitel zurückgeben.
        if (count($scenesData) === 1) {
            $scene = $scenesData[0];
            return [[
                'title' => $scene['title'] ?? 'Kapitel 1',
                'startSeconds' => (int)$scene['start'],
                'endSeconds' => (int)$scene['end'],
                'description' => 'Zusammenfassung des gesamten Videos.',
            ]];
        }

        $prompt = 'Hier ist eine Übersicht über alle Szenen eines Videos mit ihren zugeordneten Titeln und Tags/Kategorien: ' . json_encode($scenesData) . '.
            Bitte schlage basierend auf diesen Informationen sinnvolle Kapitel vor, um das Video zu strukturieren.
            Wichtig:
            - Wenn die Szenen inhaltlich sehr ähnlich oder identisch sind, oder wenn das Video insgesamt kurz ist, erstelle zwingend nur ein einziges, umfassendes Kapitel für das gesamte Video.
            - Vermeide redundante oder sich stark überschneidende Kapitel strikt.
            - Fasse inhaltlich ähnliche Szenen zu einem Kapitel zusammen.
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

