<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Transcription;

use App\Service\Transcription\TranscriptSegmentFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TranscriptSegmentFilterTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function hallucinations(): iterable
    {
        yield 'ZDF-Abspann' => ['Untertitel im Auftrag des ZDF, 2021'];
        yield 'Amara' => ['Untertitel der Amara.org-Community'];
        yield 'YouTube-Floskel' => ['Vielen Dank fürs Zuschauen!'];
        yield 'englisch' => ['Thank you for watching.'];
        yield 'nur Satzzeichen' => ['...'];
    }

    #[DataProvider('hallucinations')]
    public function testDropsTypicalWhisperHallucinations(string $text): void
    {
        self::assertSame([], new TranscriptSegmentFilter()->filter([self::raw($text)]));
    }

    public function testKeepsRealSpeechAndNormalizesWhitespace(): void
    {
        $segments = new TranscriptSegmentFilter()->filter([self::raw("  Joscha,   Butter ist ja\nnur Fett. ")]);

        self::assertCount(1, $segments);
        self::assertSame('Joscha, Butter ist ja nur Fett.', $segments[0]->getText());
        self::assertSame(1.0, $segments[0]->getStartSeconds());
    }

    public function testDropsUncertainSegmentsThatAreProbablyNoSpeech(): void
    {
        $noise = self::raw('Hm hm', avgLogprob: -1.4, noSpeechProb: 0.8);
        $quietButClear = self::raw('Hallo?', avgLogprob: -0.3, noSpeechProb: 0.8);

        $segments = new TranscriptSegmentFilter()->filter([$noise, $quietButClear]);

        self::assertSame(['Hallo?'], array_map(static fn($s): string => $s->getText(), $segments));
    }

    /**
     * @return array{start: float, end: float, text: string, avg_logprob: float, no_speech_prob: float}
     */
    private static function raw(string $text, float $avgLogprob = -0.3, float $noSpeechProb = 0.01): array
    {
        return [
            'start' => 1.0,
            'end' => 3.5,
            'text' => $text,
            'avg_logprob' => $avgLogprob,
            'no_speech_prob' => $noSpeechProb,
        ];
    }
}
