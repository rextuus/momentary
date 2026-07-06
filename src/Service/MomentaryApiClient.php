<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Service für die Kommunikation mit Willis API Platform Backend.
 * Implementiert eine intelligente ODER-Suche für das Kiosk-Tablet.
 */
final class MomentaryApiClient
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly LoggerInterface $logger,
        private readonly string $baseUrl,
        private readonly string $apiToken,
    ) {
    }

    /**
     * Holt alle identifizierten Personen im Hydra-Format.
     *
     * @return array<int, mixed>
     */
    public function getIdentifiedPersons(): array
    {
        try {
            $response = $this->request('GET', '/api/people');
            $data = $response->toArray();

            return $data['hydra:member'] ?? [];
        } catch (\Exception $e) {
            $this->logger->error('Momentary API Error (getIdentifiedPersons): ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Universalsuche für Videos.
     * Durchsucht nacheinander Titel, Tags und Personen, um die UND-Verknüpfung der API zu umgehen.
     *
     * @return array<int, mixed>
     */
    public function searchVideos(?string $query = null): array
    {
        if (!$query) {
            try {
                $response = $this->request('GET', '/api/videos');
                $data = $response->toArray();
                return $data['hydra:member'] ?? [];
            } catch (\Exception $e) {
                $this->logger->error('Momentary API Error (getAllVideos): ' . $e->getMessage());
                // Jimmy: Debug-Ausgabe hinzugefügt
                dd('API-FEHLER DETAILS (getAllVideos): ' . $e->getMessage());
            }
        }

        // 1. Versuch: Suche über den Videotitel
        try {
            $response = $this->request('GET', '/api/videos', [
                'query' => ['title' => $query]
            ]);
            $data = $response->toArray();
            $results = $data['hydra:member'] ?? [];
            
            if (!empty($results)) {
                return $results;
            }
        } catch (\Exception $e) {
            $this->logger->error('Momentary API Search Error (title): ' . $e->getMessage());
            // Jimmy: Debug-Ausgabe hinzugefügt
            dd('API-FEHLER DETAILS (title): ' . $e->getMessage());
        }

        // 2. Versuch: Suche über Szenen-Tags
        try {
            $response = $this->request('GET', '/api/videos', [
                'query' => ['scenes.tags.name' => $query]
            ]);
            $data = $response->toArray();
            $results = $data['hydra:member'] ?? [];
            
            if (!empty($results)) {
                return $results;
            }
        } catch (\Exception $e) {
            $this->logger->error('Momentary API Search Error (tags): ' . $e->getMessage());
            // Jimmy: Debug-Ausgabe hinzugefügt
            dd('API-FEHLER DETAILS (tags): ' . $e->getMessage());
        }

        // 3. Versuch: Suche über vorkommende Personen
        try {
            $response = $this->request('GET', '/api/videos', [
                'query' => ['videoFaces.person.name' => $query]
            ]);
            $data = $response->toArray();
            return $data['hydra:member'] ?? [];
        } catch (\Exception $e) {
            $this->logger->error('Momentary API Search Error (person): ' . $e->getMessage());
            // Jimmy: Debug-Ausgabe hinzugefügt
            dd('API-FEHLER DETAILS (person): ' . $e->getMessage());
        }
    }

    /**
     * Interner Helfer für API-Anfragen mit erforderlichen Headern und striktem Timeout.
     */
    private function request(string $method, string $path, array $options = []): ResponseInterface
    {
        $defaultOptions = [
            'headers' => [
                'X-AUTH-TOKEN' => $this->apiToken,
                'Accept' => 'application/ld+json',
            ],
            'timeout' => 2.5,
        ];

        return $this->client->request(
            $method,
            rtrim($this->baseUrl, '/') . $path,
            array_merge_recursive($defaultOptions, $options)
        );
    }
}
