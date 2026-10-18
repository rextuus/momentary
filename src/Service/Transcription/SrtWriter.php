<?php

declare(strict_types=1);

namespace App\Service\Transcription;

use App\Entity\TranscriptSegment;

/**
 * Erzeugt SubRip-Untertitel (.srt) aus Transkript-Abschnitten.
 */
class SrtWriter
{
    /** Längere Abschnitte werden aufgeteilt, damit kein Untertitel minutenlang stehen bleibt */
    private const float MAX_CUE_SECONDS = 7.0;

    /** Zeichen pro Untertitel-Zeile, ab da wird umgebrochen */
    private const int LINE_LENGTH = 42;

    /**
     * @param iterable<TranscriptSegment> $segments
     */
    public function write(iterable $segments): string
    {
        $cues = [];
        foreach ($segments as $segment) {
            foreach ($this->split($segment) as $cue) {
                $cues[] = $cue;
            }
        }

        $srt = '';
        foreach ($cues as $index => [$start, $end, $text]) {
            $srt .= sprintf(
                "%d\n%s --> %s\n%s\n\n",
                $index + 1,
                self::timestamp($start),
                self::timestamp($end),
                wordwrap($text, self::LINE_LENGTH, "\n", false),
            );
        }

        return $srt;
    }

    /**
     * Teilt lange Abschnitte nach Sätzen bzw. Wörtern; die Zeit wird anteilig nach Textlänge verteilt.
     *
     * @return list<array{float, float, string}>
     */
    private function split(TranscriptSegment $segment): array
    {
        $start = $segment->getStartSeconds();
        $end = $segment->getEndSeconds();
        $text = $segment->getText();
        $parts = (int) ceil(($end - $start) / self::MAX_CUE_SECONDS);
        if ($parts <= 1) {
            return [[$start, $end, $text]];
        }

        $sentences = preg_split('/(?<=[.!?…])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];
        $chunks = \count($sentences) >= $parts ? $sentences : self::splitWords($text, $parts);

        $total = max(1, array_sum(array_map(mb_strlen(...), $chunks)));
        $cues = [];
        $cursor = $start;
        $last = \count($chunks) - 1;
        foreach ($chunks as $i => $chunk) {
            $length = (($end - $start) * mb_strlen($chunk)) / $total;
            // Rundungsreste nicht verlieren: der letzte Untertitel endet genau mit dem Abschnitt
            $cues[] = [$cursor, $i === $last ? $end : min($end, $cursor + $length), trim($chunk)];
            $cursor += $length;
        }

        return $cues;
    }

    /**
     * @return list<string>
     */
    private static function splitWords(string $text, int $parts): array
    {
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];
        $perChunk = max(1, (int) ceil(\count($words) / max(1, $parts)));

        return array_map(static fn(array $chunk): string => implode(' ', $chunk), array_chunk($words, $perChunk));
    }

    public static function timestamp(float $seconds): string
    {
        $millis = (int) round($seconds * 1000);

        return sprintf(
            '%02d:%02d:%02d,%03d',
            intdiv($millis, 3_600_000),
            intdiv($millis % 3_600_000, 60_000),
            intdiv($millis % 60_000, 1000),
            $millis % 1000,
        );
    }
}
