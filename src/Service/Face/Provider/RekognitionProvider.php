<?php

declare(strict_types=1);

namespace App\Service\Face\Provider;

use App\Entity\Medium;
use App\Service\Aws\AmazonRekognitionService;

/**
 * AWS Rekognition (kostet pro Aufruf). Die Kandidaten sucht AWS in der eigenen Collection.
 */
final class RekognitionProvider implements FaceRecognitionProviderInterface
{
    public const string NAME = 'rekognition';

    public function __construct(
        private readonly AmazonRekognitionService $rekognition,
    ) {}

    public static function getName(): string
    {
        return self::NAME;
    }

    public function detectFaces(string $imagePath, Medium $medium): array
    {
        return $this->rekognition->processAllFacesInImage($imagePath);
    }

    public function forgetFaces(array $faceLabels): void
    {
        if ($faceLabels !== []) {
            $this->rekognition->deleteFaces($faceLabels);
        }
    }
}
