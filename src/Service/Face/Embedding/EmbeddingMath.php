<?php

declare(strict_types=1);

namespace App\Service\Face\Embedding;

/**
 * Rechnen mit Gesichts-Embeddings (ArcFace, 512 Werte).
 *
 * MySQL kennt keinen Vektortyp mit Distanzfunktionen, deshalb liegen Embeddings als float32-Binärdaten in der Datenbank
 * (2 KB statt ~10 KB JSON) und werden hier in PHP verglichen.
 */
final class EmbeddingMath
{
    /**
     * Als float32, little endian (unabhängig von der Plattform).
     *
     * @param list<float> $vector
     */
    public static function pack(array $vector): string
    {
        return pack('g*', ...$vector);
    }

    /**
     * @return list<float>
     */
    public static function unpack(string $binary): array
    {
        if ($binary === '' || (\strlen($binary) % 4) !== 0) {
            throw new \InvalidArgumentException(sprintf('Kein float32-Vektor (%d Bytes).', \strlen($binary)));
        }
        $values = unpack('g*', $binary);
        if ($values === false) {
            throw new \InvalidArgumentException('Embedding konnte nicht gelesen werden.');
        }

        return array_values(array_map(floatval(...), $values));
    }

    /**
     * @param list<float> $vector
     *
     * @return list<float>
     */
    public static function normalize(array $vector): array
    {
        $norm = sqrt(self::dot($vector, $vector));
        if ($norm === 0.0) {
            return $vector;
        }

        return array_map(static fn(float $value): float => $value / $norm, $vector);
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    public static function dot(array $a, array $b): float
    {
        $count = \count($a);
        if ($count !== \count($b)) {
            throw new \InvalidArgumentException(sprintf('Unterschiedliche Länge: %d und %d.', $count, \count($b)));
        }

        $sum = 0.0;
        for ($i = 0; $i < $count; $i++) {
            $sum += $a[$i] * $b[$i];
        }

        return $sum;
    }

    /**
     * Kosinus-Ähnlichkeit, -1 bis 1. Bei normalisierten Vektoren (wie vom face-service) gleich dem Skalarprodukt.
     *
     * @param list<float> $a
     * @param list<float> $b
     */
    public static function cosine(array $a, array $b): float
    {
        $norms = sqrt(self::dot($a, $a) * self::dot($b, $b));
        if ($norms === 0.0) {
            return 0.0;
        }

        return max(-1.0, min(1.0, self::dot($a, $b) / $norms));
    }

    /**
     * Die ähnlichsten Referenzen ab einer Mindestähnlichkeit, absteigend sortiert.
     *
     * Erwartet normalisierte Vektoren (Skalarprodukt statt Kosinus, spart die Normen bei jedem Vergleich).
     *
     * @template K of array-key
     *
     * @param list<float>          $query
     * @param iterable<K, list<float>> $references
     *
     * @return array<K, float> Schlüssel der Referenz => Ähnlichkeit
     */
    public static function topK(array $query, iterable $references, float $minSimilarity, int $limit): array
    {
        $hits = [];
        foreach ($references as $key => $reference) {
            $similarity = self::dot($query, $reference);
            if ($similarity >= $minSimilarity) {
                $hits[$key] = $similarity;
            }
        }
        arsort($hits);

        return \array_slice($hits, 0, max(0, $limit), true);
    }
}
