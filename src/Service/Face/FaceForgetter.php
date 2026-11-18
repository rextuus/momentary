<?php

declare(strict_types=1);

namespace App\Service\Face;

use App\Entity\VideoFace;
use App\Service\Face\Provider\FaceRecognitionProviders;
use Psr\Log\LoggerInterface;

/**
 * Meldet gelöschte Gesichter dem Anbieter, der sie erkannt hat (Rekognition: aus der AWS-Collection löschen).
 *
 * Ein Anbieter wird nur gefragt, wenn wirklich Gesichter von ihm dabei sind; ohne Rekognition-Gesichter gibt es also
 * keinen AWS-Aufruf. Fehler (z. B. abgelaufener AWS-Zugang) werden nur geloggt und blockieren das Löschen nie.
 */
class FaceForgetter
{
    public function __construct(
        private readonly FaceRecognitionProviders $providers,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Labels je Anbieter einsammeln, bevor die Gesichter gelöscht werden.
     *
     * @param iterable<VideoFace> $faces
     *
     * @return array<string, list<string>> Anbieter => faceLabels
     */
    public static function labelsByProvider(iterable $faces): array
    {
        $labels = [];
        foreach ($faces as $face) {
            $label = $face->getFaceLabel();
            if ($label !== null && $label !== '') {
                $labels[$face->getProvider()][] = $label;
            }
        }

        return $labels;
    }

    /**
     * @param array<string, list<string>> $labelsByProvider aus labelsByProvider()
     */
    public function forget(array $labelsByProvider, string $context): void
    {
        foreach ($labelsByProvider as $provider => $labels) {
            if ($labels === []) {
                continue;
            }
            try {
                $this->providers->get($provider)->forgetFaces(array_values(array_unique($labels)));
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf(
                    '%s: %d Gesichter bei %s nicht entfernt: %s',
                    $context,
                    \count($labels),
                    $provider,
                    $e->getMessage(),
                ));
            }
        }
    }
}
