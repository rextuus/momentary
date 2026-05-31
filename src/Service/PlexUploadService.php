<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class PlexUploadService
{
    private string $uploadDir;
    private string $plexHost;
    private ?string $plexToken;

    public function __construct(
        #[Autowire('%kernel.project_dir%/docker/plex/uploads')] string $uploadDir,
        string $plexHost,
        ?string $plexToken,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger
    ) {
        $this->uploadDir = $uploadDir;
        $this->plexHost = rtrim($plexHost, '/');
        $this->plexToken = $plexToken;
    }

    /**
     * "Uploads" a video by moving it to the Plex watched directory.
     * 
     * @param string $sourcePath Path to the local video file.
     * @param string $filename The desired filename in the Plex directory.
     * @return string|bool The final path on success, false on failure.
     */
    public function uploadVideo(string $sourcePath, string $filename): string|bool
    {
        if (!file_exists($sourcePath)) {
            $this->logger->error("Source video file does not exist: $sourcePath");
            return false;
        }

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0777, true);
        }

        $destinationPath = $this->uploadDir . DIRECTORY_SEPARATOR . $filename;
        
        if (copy($sourcePath, $destinationPath)) {
            $this->logger->info("Video successfully uploaded to Plex directory: $destinationPath");
            
            // Trigger Plex library scan if token is provided
            $this->triggerScan();
            
            return $destinationPath;
        }

        $this->logger->error("Failed to copy video to Plex directory: $destinationPath");
        return false;
    }

    /**
     * Triggers a scan of all libraries in Plex.
     */
    public function triggerScan(): void
    {
        if (!$this->plexToken) {
            $this->logger->warning("Plex token not configured, skipping library scan trigger.");
            return;
        }

        try {
            // First, get the library sections
            $response = $this->httpClient->request('GET', "{$this->plexHost}/library/sections", [
                'headers' => [
                    'X-Plex-Token' => $this->plexToken,
                    'Accept' => 'application/json',
                ],
            ]);

            if ($response->getStatusCode() === 200) {
                $data = $response->toArray();
                $sections = $data['MediaContainer']['Directory'] ?? [];

                foreach ($sections as $section) {
                    $sectionId = $section['key'];
                    $this->httpClient->request('GET', "{$this->plexHost}/library/sections/{$sectionId}/refresh", [
                        'headers' => [
                            'X-Plex-Token' => $this->plexToken,
                        ],
                    ]);
                    $this->logger->info("Triggered refresh for Plex library section: {$sectionId}");
                }
            }
        } catch (\Exception $e) {
            $this->logger->error("Failed to trigger Plex scan: " . $e->getMessage());
        }
    }
}
