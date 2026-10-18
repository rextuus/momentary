<?php

declare(strict_types=1);

namespace App\Service\Transcription;

use App\Entity\TranscriptSegment;

/**
 * Wandelt die Whisper-Ausgabe in Abschnitte um und verwirft typische Halluzinationen.
 *
 * Whisper "hört" bei Stille, Musik oder Rauschen gern Sätze aus seinen Trainingsdaten (Untertitel-Abspänne).
 * Die Spracherkennung (VAD) im Skript fängt das meiste ab; was durchrutscht, wird hier aussortiert.
 */
class TranscriptSegmentFilter
{
    private const string HALLUCINATIONS =
        '/untertitel (im auftrag|der amara|von|by)|amara\.org|vielen dank (fürs|für das|für\'s) zuschauen'
            . '|thank(s| you) for watching|abonniert? (den kanal|meinen kanal)|copyright .*(zdf|ard|wdr|swr)|^(\W*)$/iu';

    /**
     * @param list<array{start: float, end: float, text: string, avg_logprob: float, no_speech_prob: float}> $raw
     *
     * @return list<TranscriptSegment>
     */
    public function filter(array $raw): array
    {
        $segments = [];
        foreach ($raw as $segment) {
            $text = trim(preg_replace('/\s+/u', ' ', $segment['text']) ?? '');
            if ($text === '' || preg_match(self::HALLUCINATIONS, $text) === 1) {
                continue;
            }
            // Wahrscheinlich keine Sprache und dabei unsicher: eher Rauschen als ein Satz
            if ($segment['no_speech_prob'] > 0.6 && $segment['avg_logprob'] < -1.0) {
                continue;
            }
            if ($segment['end'] <= $segment['start']) {
                continue;
            }

            $segments[] = new TranscriptSegment($segment['start'], $segment['end'], $text, $segment['avg_logprob']);
        }

        return $segments;
    }
}
