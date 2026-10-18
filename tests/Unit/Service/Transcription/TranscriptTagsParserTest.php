<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Transcription;

use App\Service\Gemini\GeminiService;
use PHPUnit\Framework\TestCase;

final class TranscriptTagsParserTest extends TestCase
{
    public function testAcceptsPlainAndPrefixedSceneKeysAndDropsEmptyCategories(): void
    {
        $response = <<<'JSON'
            ```json
            {
              "Szene 177": {"Themen": ["Essen", " Butter "], "Erwähnte Personen": ["Joscha"], "Orte": []},
              "182": {"Anlässe": ["Geburtstag"]},
              "Zusammenfassung": "kein Szenen-Eintrag"
            }
            ```
            JSON;

        self::assertSame(
            [
                177 => ['Themen' => ['Essen', 'Butter'], 'Erwähnte Personen' => ['Joscha']],
                182 => ['Anlässe' => ['Geburtstag']],
            ],
            GeminiService::parseTranscriptTags($response),
        );
    }

    public function testInvalidJsonGivesNoTags(): void
    {
        self::assertSame([], GeminiService::parseTranscriptTags('Leider kann ich das nicht.'));
    }
}
