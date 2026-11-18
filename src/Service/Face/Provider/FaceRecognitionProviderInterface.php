<?php

declare(strict_types=1);

namespace App\Service\Face\Provider;

use App\Entity\Medium;
use App\Service\Face\DetectedFace;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Ein Anbieter der Gesichtserkennung. Welcher gilt, legt FACE_RECOGNITION_PROVIDER fest (FaceRecognitionProviders).
 */
#[AutoconfigureTag(self::TAG)]
interface FaceRecognitionProviderInterface
{
    public const string TAG = 'app.face_recognition_provider';

    /**
     * Name, unter dem der Anbieter in FACE_RECOGNITION_PROVIDER und in VideoFace::provider steht.
     */
    public static function getName(): string;

    /**
     * Gesichter eines Bildes samt Kandidaten (ähnliche, schon gespeicherte Gesichter).
     *
     * @param Medium $medium Video oder Foto, zu dem das Bild gehört; Gesichter desselben Mediums sind immer Kandidaten
     *
     * @return list<DetectedFace>
     */
    public function detectFaces(string $imagePath, Medium $medium): array;

    /**
     * Gelöschte Gesichter beim Anbieter vergessen, damit sie nicht mehr als Kandidaten auftauchen.
     *
     * @param list<string> $faceLabels VideoFace::faceLabel
     */
    public function forgetFaces(array $faceLabels): void;
}
