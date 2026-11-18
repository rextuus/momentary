<?php

declare(strict_types=1);

namespace App\Service\Face\InsightFace;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP-Client für den face-service (InsightFace, eigener Container, face-service/README.md).
 *
 * Bilder gehen per multipart hin; der Dienst braucht dadurch keinen Zugriff auf den Medienspeicher. Er bearbeitet
 * immer nur ein Bild zur Zeit, der Timeout deckt deshalb auch das Warten auf eine laufende Anfrage ab.
 */
class FaceServiceClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(FACE_SERVICE_URL)%')]
        private readonly string $baseUrl,
        #[Autowire('%env(float:FACE_SERVICE_TIMEOUT)%')]
        private readonly float $timeout = 60.0,
    ) {}

    /**
     * Nur Detektion: Boxen, Scores, Landmarks.
     *
     * @param int|null $minFacePx Gesichter mit kürzerer Box-Seite darunter werden nicht zurückgegeben
     * @param int|null $maxFaces  höchstens so viele, die größten zuerst; 0 = alle
     */
    public function detect(
        string $imagePath,
        ?int $detSize = null,
        ?float $minScore = null,
        ?int $minFacePx = null,
        ?int $maxFaces = null,
    ): FaceServiceResult {
        return $this->send('detect', $imagePath, $detSize, $minScore, $minFacePx, $maxFaces);
    }

    /**
     * Detektion plus Embedding, Alter und Geschlecht pro Gesicht.
     *
     * @param int|null $minFacePx Gesichter mit kürzerer Box-Seite darunter werden nicht zurückgegeben
     * @param int|null $maxFaces  höchstens so viele, die größten zuerst; 0 = alle
     */
    public function analyze(
        string $imagePath,
        ?int $detSize = null,
        ?float $minScore = null,
        ?int $minFacePx = null,
        ?int $maxFaces = null,
    ): FaceServiceResult {
        return $this->send('analyze', $imagePath, $detSize, $minScore, $minFacePx, $maxFaces);
    }

    /**
     * Zustand des Dienstes (Modell, det_size, Threads …).
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->url('health'), ['timeout' => 5]);

            return $response->toArray();
        } catch (ExceptionInterface $e) {
            throw new FaceServiceException(sprintf('face-service nicht erreichbar: %s', $e->getMessage()), 0, $e);
        }
    }

    private function send(
        string $endpoint,
        string $imagePath,
        ?int $detSize,
        ?float $minScore,
        ?int $minFacePx,
        ?int $maxFaces,
    ): FaceServiceResult {
        if (!is_file($imagePath) || !is_readable($imagePath)) {
            throw new FaceServiceException(sprintf('Bild "%s" ist nicht lesbar.', $imagePath));
        }

        $query = array_filter(
            [
                'det_size' => $detSize,
                'min_score' => $minScore,
                'min_face_px' => $minFacePx,
                'max_faces' => $maxFaces,
            ],
            static fn(int|float|null $value): bool => $value !== null,
        );
        $form = new FormDataPart(['image' => DataPart::fromPath($imagePath)]);

        try {
            $response = $this->httpClient->request('POST', $this->url($endpoint), [
                'query' => $query,
                'headers' => $form->getPreparedHeaders()->toArray(),
                'body' => $form->bodyToString(),
                'timeout' => $this->timeout,
                'max_duration' => $this->timeout,
            ]);
            $status = $response->getStatusCode();
            if ($status !== 200) {
                throw new FaceServiceException(sprintf(
                    'face-service /%s antwortet mit %d: %s',
                    $endpoint,
                    $status,
                    mb_substr($response->getContent(false), 0, 300),
                ));
            }
            $data = $response->toArray();
        } catch (ExceptionInterface $e) {
            throw new FaceServiceException(sprintf('face-service /%s: %s', $endpoint, $e->getMessage()), 0, $e);
        }

        return FaceServiceResultParser::parse($data);
    }

    private function url(string $endpoint): string
    {
        return rtrim($this->baseUrl, '/') . '/' . $endpoint;
    }
}
