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

class GeminiService
{
    private Client $client;

    public function __construct(
        #[\SensitiveParameter]
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
                new Blob(mimeType: MimeType::IMAGE_JPEG, data: base64_encode($this->readImage($imageUrl))),
            ]);

        foreach ($response->parts() as $part) {
            if ($part->inlineData !== null) {
                $image = base64_decode($part->inlineData->data, strict: true);
                if ($image === false) {
                    throw new RuntimeException('Gemini returned image data that is not valid base64.');
                }

                return $image;
            }
        }

        throw new RuntimeException(
            'Gemini did not return an image in the response. Check safety ratings or response content.',
        );
    }

    /**
     * @return array<string, mixed> Tags und Titel aus der Gemini-Antwort
     */
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
                new Blob(mimeType: MimeType::IMAGE_JPEG, data: base64_encode($this->readImage($imagePath))),
            ]);

        $text = $response->text();
        fwrite(STDOUT, '[Gemini] Raw response: ' . $text . PHP_EOL);
        $text = str_replace(['```json', '```'], '', $text);

        $decoded = json_decode(trim($text), true);
        if ($decoded === null) {
            fwrite(STDOUT, '[Gemini] JSON decode failed for: ' . $text . PHP_EOL);
        }
        return $decoded ?? [];
    }

    /**
     * Tags je Szene aus dem Gesprochenen ableiten (nur Text, keine Bilder).
     *
     * @param array<int, string> $sceneTexts Szenen-ID => transkribierter Text
     *
     * @return array<int, array<string, list<string>>> Szenen-ID => Kategorie => Tags
     */
    public function tagTranscript(array $sceneTexts): array
    {
        $lines = [];
        foreach ($sceneTexts as $sceneId => $text) {
            $lines[] = sprintf('Szene %d: %s', $sceneId, $text);
        }

        $prompt =
            'Hier ist das Transkript eines privaten Familienvideos, aufgeteilt nach Szenen. '
            . 'Leite für jede Szene Schlagworte ab, nach denen man später suchen würde. Nur was tatsächlich '
            . 'gesagt wird, nichts erfinden; Füllwörter und Belangloses weglassen. Szenen ohne Aussagekraft weglassen. '
            . 'Kategorien: "Themen" (worüber gesprochen wird), "Erwähnte Personen" (nur Vornamen/Namen, die fallen), '
            . '"Orte", "Anlässe" (z. B. Geburtstag, Hochzeit, Urlaub). Höchstens 5 Tags pro Kategorie, jeweils 1–3 Wörter. '
            . 'Antworte ausschließlich als JSON: {"<Szenen-ID>": {"Themen": [...], "Erwähnte Personen": [...], '
            . '"Orte": [...], "Anlässe": [...]}}'
            . "\n\n"
            . mb_substr(implode("\n", $lines), 0, 30000);

        $response = $this->client->generativeModel(model: 'gemini-3.1-flash-lite')->generateContent($prompt);
        $text = $response->text();
        fwrite(STDOUT, '[Gemini] Transkript-Tags: ' . $text . PHP_EOL);
        return self::parseTranscriptTags($text);
    }

    /**
     * Antwort von tagTranscript() auswerten. Gemini schreibt die Schlüssel mal als "177", mal als "Szene 177".
     *
     * @return array<int, array<string, list<string>>>
     */
    public static function parseTranscriptTags(string $text): array
    {
        $decoded = json_decode(trim(str_replace(['```json', '```'], '', $text)), true);
        if (!\is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $key => $categories) {
            if (preg_match('/(\d+)/', (string) $key, $match) !== 1 || !\is_array($categories)) {
                continue;
            }
            foreach ($categories as $category => $tags) {
                if (!\is_array($tags)) {
                    continue;
                }
                $tags = array_values(array_filter(
                    array_map(static fn(mixed $tag): string => trim((string) $tag), $tags),
                    static fn(string $tag): bool => $tag !== '',
                ));
                if ($tags !== []) {
                    $result[(int) $match[1]][(string) $category] = $tags;
                }
            }
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $scenesData
     *
     * @return list<array<string, mixed>>
     */
    public function suggestChapters(array $scenesData): array
    {
        // Shortcut: Wenn nur eine Szene vorhanden ist, direkt ein Kapitel zurückgeben.
        if (count($scenesData) === 1) {
            $scene = $scenesData[0];
            return [[
                'title' => $scene['title'] ?? 'Kapitel 1',
                'startSeconds' => (int) $scene['start'],
                'endSeconds' => (int) $scene['end'],
                'description' => 'Zusammenfassung des gesamten Videos.',
            ]];
        }

        $prompt =
            'Hier ist eine Übersicht über alle Szenen eines Videos mit ihren zugeordneten Titeln und Tags/Kategorien: '
            . json_encode($scenesData)
            . '.
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
                $prompt,
            ]);

        $text = $response->text();
        $text = str_replace(['```json', '```'], '', $text);

        return json_decode(trim($text), true) ?? [];
    }

    /**
     * Titel für ein Ereignis (z. B. "Mallorca-Urlaub", "Omas 80. Geburtstag") aus Tags und Medientiteln.
     *
     * @param list<string> $tags
     * @param list<string> $mediaTitles
     */
    public function suggestEventTitle(array $tags, array $mediaTitles, string $period): string
    {
        $prompt =
            'Hier sind Informationen zu Fotos und Videos aus einem privaten Familienarchiv, die zeitlich zusammengehören. '
            . 'Schlage einen kurzen Titel (2–5 Wörter) für dieses Ereignis vor, wie ihn eine Familie für ein Album wählen würde, '
            . 'z. B. "Sommerurlaub auf Mallorca" oder "Omas Geburtstag". Keine Anführungszeichen, kein Datum im Titel. '
            . 'Zeitraum: '
            . $period
            . '. '
            . 'Häufige Tags: '
            . implode(', ', $tags)
            . '. '
            . 'Titel einzelner Aufnahmen: '
            . implode('; ', \array_slice($mediaTitles, 0, 30))
            . '. '
            . 'Antworte ausschließlich mit dem Titel.';

        $response = $this->client->generativeModel(model: 'gemini-3.1-flash-lite')->generateContent($prompt);

        return trim($response->text(), " \n\r\t\"'„“");
    }

    /**
     * @param list<string>                $tags
     * @param list<array<string, mixed>>  $sceneSummaries
     */
    public function suggestVideoName(array $tags, array $sceneSummaries, string $filename, ?string $createdAt): string
    {
        $promptParts = ['Schlage einen prägnanten Titel für dieses Video vor.'];

        if (!empty($tags)) {
            $promptParts[] = 'Tags: ' . implode(', ', $tags) . '.';
        }
        if (!empty($sceneSummaries)) {
            $promptParts[] = 'Szenen/Kapitel-Zusammenfassungen: ' . json_encode($sceneSummaries) . '.';
        }

        $promptParts[] = sprintf(
            'Falls diese Informationen nicht ausreichen, hier ergänzende Daten: Dateiname: %s, Erstellungsdatum: %s.',
            $filename,
            $createdAt ?? 'unbekannt',
        );
        $promptParts[] = 'Antworte ausschließlich mit dem Titel.';

        $prompt = implode(' ', $promptParts);

        $response = $this->client
            ->generativeModel(model: 'gemini-3.1-flash-lite')
            ->generateContent([
                $prompt,
            ]);

        return trim($response->text());
    }

    private function readImage(string $path): string
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException(sprintf('Image "%s" could not be read.', $path));
        }

        return $content;
    }
}
