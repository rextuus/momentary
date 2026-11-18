<?php

declare(strict_types=1);

namespace App\Service\Face\InsightFace;

/**
 * Prüft die JSON-Antwort des face-service und macht daraus typisierte Objekte.
 *
 * Die Komplexität kommt allein aus den Feldprüfungen (je Feld ein Typ-Check); aufgeteilt wären sie nur schwerer
 * zu überblicken, daher bewusste Ausnahme.
 */
// @mago-expect lint:cyclomatic-complexity
final class FaceServiceResultParser
{
    /**
     * @param array<mixed> $data
     */
    public static function parse(array $data): FaceServiceResult
    {
        $faces = [];
        foreach (self::list($data, 'faces') as $face) {
            if (!\is_array($face)) {
                throw new FaceServiceException('face-service: Gesicht ist kein Objekt.');
            }
            $box = array_map(self::number(...), self::list($face, 'box'));
            if (\count($box) !== 4) {
                throw new FaceServiceException('face-service: Box braucht vier Werte.');
            }
            $embedding = isset($face['embedding'])
                ? array_map(self::number(...), self::list($face, 'embedding'))
                : null;
            $gender = $face['gender'] ?? null;

            $faces[] = new ServiceFace(
                box: [$box[0], $box[1], $box[2], $box[3]],
                score: self::number($face['score'] ?? null),
                landmarks: array_map(
                    static function (mixed $point): array {
                        $point = \is_array($point) ? array_values($point) : [];

                        return [self::number($point[0] ?? null), self::number($point[1] ?? null)];
                    },
                    isset($face['landmarks']) ? self::list($face, 'landmarks') : [],
                ),
                embedding: $embedding,
                age: isset($face['age']) ? (int) self::number($face['age']) : null,
                gender: $gender === 'M' || $gender === 'F' ? $gender : null,
            );
        }

        $filtered = \is_array($data['filtered'] ?? null) ? $data['filtered'] : [];
        $timings = \is_array($data['timings_ms'] ?? null) ? $data['timings_ms'] : [];
        $width = (int) self::number($data['width'] ?? null);
        $height = (int) self::number($data['height'] ?? null);
        if ($width <= 0 || $height <= 0) {
            throw new FaceServiceException('face-service: Bildgröße fehlt.');
        }

        return new FaceServiceResult(
            model: \is_string($data['model'] ?? null) ? $data['model'] : 'unknown',
            width: $width,
            height: $height,
            detSize: (int) self::number($data['det_size'] ?? 0),
            faces: $faces,
            filteredTooSmall: (int) self::number($filtered['too_small'] ?? 0),
            filteredOverLimit: (int) self::number($filtered['over_limit'] ?? 0),
            timingsMs: array_map(self::number(...), array_filter($timings, is_numeric(...))),
        );
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<mixed>
     */
    private static function list(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!\is_array($value)) {
            throw new FaceServiceException(sprintf('face-service: Feld "%s" fehlt.', $key));
        }

        return array_values($value);
    }

    private static function number(mixed $value): float
    {
        if (!is_numeric($value)) {
            throw new FaceServiceException('face-service: Zahl erwartet.');
        }

        return (float) $value;
    }
}
