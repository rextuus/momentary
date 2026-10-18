<?php

declare(strict_types=1);

namespace App\Service\Transcription;

use App\Entity\VideoTranscript;
use App\Service\Gemini\GeminiService;
use App\Service\Video\Analyze\TagService;

/**
 * Leitet aus dem Transkript Tags je Szene ab und hängt sie (als KI-Tags) an die Szenen.
 */
class TranscriptTagger
{
    public function __construct(
        private readonly GeminiService $gemini,
        private readonly TagService $tagService,
    ) {}

    /**
     * @return int Anzahl Szenen, die Tags bekommen haben
     */
    public function tag(VideoTranscript $transcript): int
    {
        $scenes = [];
        $texts = [];
        foreach ($transcript->getVideo()->getScenes() as $scene) {
            $text = implode(' ', array_map(
                static fn($segment): string => $segment->getText(),
                $transcript->segmentsBetween((float) $scene->getStartSeconds(), (float) $scene->getEndSeconds()),
            ));
            if ($scene->getId() !== null && mb_strlen($text) >= 15) {
                $scenes[$scene->getId()] = $scene;
                $texts[$scene->getId()] = $text;
            }
        }
        if ($texts === []) {
            return 0;
        }

        $tagged = 0;
        foreach ($this->gemini->tagTranscript($texts) as $sceneId => $categories) {
            if (isset($scenes[$sceneId]) && array_filter($categories) !== []) {
                $this->tagService->assignAiTagsToScene($scenes[$sceneId], $categories);
                $tagged++;
            }
        }

        return $tagged;
    }
}
