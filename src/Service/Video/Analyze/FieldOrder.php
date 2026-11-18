<?php

declare(strict_types=1);

namespace App\Service\Video\Analyze;

/**
 * Halbbild-Reihenfolge (Interlacing) eines Videos: Werte wie ffprobe sie als field_order liefert.
 *
 * t = oberes, b = unteres Halbbild; der erste Buchstabe ist das zuerst codierte, der zweite das zuerst angezeigte
 * (bei tb/bt also das jeweils andere). Erkannt wird in Mp4Converter::detectFieldOrder.
 */
final class FieldOrder
{
    public const array INTERLACED = ['tt', 'bb', 'tb', 'bt'];

    public const string PROGRESSIVE = 'progressive';

    public static function isInterlaced(?string $fieldOrder): bool
    {
        return \in_array($fieldOrder, self::INTERLACED, true);
    }

    /**
     * Auswertung von idet ("Multi frame detection: TFF: 412 BFF: 0 Progressive: 3 Undetermined: 185").
     * Interlaced nur, wenn eindeutig: deutlich mehr Halbbild- als Vollbild-Treffer und genug Bilder.
     *
     * @return string|null tt, bb oder progressive; null, wenn idet nichts geliefert hat
     */
    public static function fromIdet(string $output): ?string
    {
        // ffmpeg gibt die Zusammenfassung zweimal aus; die erste (nur Nullen) stammt von einer verworfenen Filterkette
        $found = preg_match_all(
            '/Multi frame detection:\s*TFF:\s*(\d+)\s*BFF:\s*(\d+)\s*Progressive:\s*(\d+)/',
            $output,
            $matches,
            \PREG_SET_ORDER,
        );
        if (!$found) {
            return null;
        }
        $m = $matches[\count($matches) - 1];
        [$tff, $bff, $progressive] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        $interlaced = $tff + $bff;
        if ($interlaced < 20 || $interlaced < (2 * $progressive)) {
            return self::PROGRESSIVE;
        }

        return $tff >= $bff ? 'tt' : 'bb';
    }

    /**
     * bwdif-Parität: welches Halbbild zuerst angezeigt wird.
     */
    public static function parity(string $fieldOrder): string
    {
        return match ($fieldOrder) {
            'tt', 'bt' => 'tff',
            'bb', 'tb' => 'bff',
            default => 'auto',
        };
    }
}
