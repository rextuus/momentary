<?php

declare(strict_types=1);

namespace App\Service\Face\InsightFace;

/**
 * Antwort des face-service auf /detect bzw. /analyze.
 */
final readonly class FaceServiceResult
{
    /**
     * @param list<ServiceFace>    $faces            nach Größe sortiert, das größte zuerst
     * @param array<string, float> $timingsMs        decode, wait, detect, embed (im Dienst gemessen)
     */
    public function __construct(
        public string $model,
        public int $width,
        public int $height,
        public int $detSize,
        public array $faces,
        public int $filteredTooSmall,
        public int $filteredOverLimit,
        public array $timingsMs,
    ) {}

    public function hasFaces(): bool
    {
        return $this->faces !== [];
    }
}
