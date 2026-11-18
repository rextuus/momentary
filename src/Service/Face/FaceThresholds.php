<?php

declare(strict_types=1);

namespace App\Service\Face;

use App\Entity\FaceRecognitionSettings;
use App\Service\Face\Provider\InsightFaceProvider;

/**
 * Schwellen des aktiven Anbieters, einheitlich auf der Skala der gespeicherten Kandidaten (0–100).
 *
 * InsightFace-Kandidaten werden als Kosinus × 100 gespeichert; die Einstellung 0,50 wird hier also zu 50.
 */
final readonly class FaceThresholds
{
    public function __construct(
        public float $candidate,
        public float $videoLink,
        public float $suggestion,
        public float $match,
        public bool $genderCheck,
    ) {}

    public static function of(FaceRecognitionSettings $settings, string $provider): self
    {
        if ($provider === InsightFaceProvider::NAME) {
            return new self(
                candidate: $settings->getInsightFaceCandidateThreshold() * 100,
                videoLink: $settings->getInsightFaceVideoLinkThreshold() * 100,
                suggestion: $settings->getInsightFaceSuggestionThreshold() * 100,
                match: $settings->getInsightFaceMatchThreshold() * 100,
                genderCheck: $settings->isInsightFaceGenderCheck(),
            );
        }

        return new self(
            candidate: $settings->getCandidateThreshold(),
            videoLink: $settings->getVideoLinkThreshold(),
            suggestion: $settings->getSuggestionThreshold(),
            match: $settings->getMatchThreshold(),
            genderCheck: true,
        );
    }
}
