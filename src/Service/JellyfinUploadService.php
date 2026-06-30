<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class JellyfinUploadService
{
    private string $uploadDir;
    private string $jellyfinHost;
    private ?string $jellyfinApiKey;

    public function __construct(
        #[Autowire('%kernel.project_dir%/docker/jellyfin/uploads')] string $uploadDir,
        string $jellyfinHost,
        ?string $jellyfinApiKey,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly VideoAnalyzer $videoAnalyzer
    ) {
        $this->uploadDir = $this->videoAnalyzer->resolvePath($uploadDir);
        $this->jellyfinHost = rtrim($jellyfinHost, '/');
        $this->jellyfinApiKey = $jellyfinApiKey;
    }

    /**
     * "Uploads" a video by moving it to the Jellyfin watched directory.
     * 
     * @param string $sourcePath Path to the local video file.
     * @param string $filename The desired filename in the Jellyfin directory.
     * @return string|bool The final path on success, false on failure.
     */
    public function uploadVideo(string $sourcePath, string $filename): string|bool
    {
        $this->logger->info("Starting Jellyfin upload process for: $filename");

        if (!file_exists($sourcePath)) {
            $this->logger->error("Source video file does not exist: $sourcePath");
            return false;
        }

        if (!is_dir($this->uploadDir)) {
            $this->logger->info("Creating upload directory: $this->uploadDir");
            mkdir($this->uploadDir, 0777, true);
        }

        $destinationPath = $this->uploadDir . DIRECTORY_SEPARATOR . $filename;
        
        $this->logger->info("Copying file from $sourcePath to $destinationPath");
        
        // Ensure upload directory exists and is writable
        if (!is_writable($this->uploadDir)) {
            $message = "Upload directory is not writable: $this->uploadDir. Please check permissions (e.g. chmod 777).";
            $this->logger->error($message);
            throw new \RuntimeException($message);
        }

        if (copy($sourcePath, $destinationPath)) {
            @chmod($destinationPath, 0666);
            $this->logger->info("Video successfully copied to Jellyfin directory: $destinationPath");
            
            // Trigger Jellyfin library scan if API key is provided
            $this->logger->info("Triggering Jellyfin library scan...");
            $this->triggerScan();
            
            return $destinationPath;
        }

        $this->logger->error("Failed to copy video to Jellyfin directory: $destinationPath");
        return false;
    }

    /**
     * Triggers a scan of all libraries in Jellyfin.
     */
    public function triggerScan(): void
    {
        if (!$this->jellyfinApiKey) {
            $this->logger->warning("Jellyfin API key not configured, skipping library scan trigger.");
            return;
        }

        try {
            $url = "{$this->jellyfinHost}/Library/Refresh";
            $this->logger->info("Requesting Jellyfin refresh via API: $url");
            
            // Jellyfin API for scheduled tasks or specific library refreshes
            // To refresh all libraries: POST /Library/Refresh
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'X-Emby-Token' => $this->jellyfinApiKey,
                ],
                'timeout' => 10, // Add a timeout to prevent hanging
            ]);

            $statusCode = $response->getStatusCode();
            $this->logger->info("Jellyfin API responded with status code: $statusCode");
            
            $content = $response->getContent(false);
            if ($content) {
                $this->logger->debug("Jellyfin API response content: " . substr($content, 0, 500));
            }

            if ($statusCode === 204 || $statusCode === 200) {
                $this->logger->info("Triggered Jellyfin library refresh.");
            } else {
                $this->logger->error("Jellyfin refresh returned status: " . $response->getStatusCode());
            }
        } catch (\Exception $e) {
            $this->logger->error("Failed to trigger Jellyfin scan: " . $e->getMessage());
        }
    }

    public function findItemIdByPath(string $path): ?string
    {
        if (!$this->jellyfinApiKey) {
            return null;
        }

        try {
            // Internal Jellyfin path normalization
            // If the path starts with the local upload dir, we make it relative to the Jellyfin internal mount point (/uploads)
            $internalPath = $path;
            if (str_contains($path, 'docker/jellyfin/uploads')) {
                $parts = explode('docker/jellyfin/uploads', $path);
                $internalPath = '/uploads' . end($parts);
            } elseif (str_contains($path, '/var/www/html/docker/jellyfin/uploads')) {
                $parts = explode('/var/www/html/docker/jellyfin/uploads', $path);
                $internalPath = '/uploads' . end($parts);
            }
            
            // Normalize path to forward slashes for API comparison
            $normalizedInternalPath = str_replace('\\', '/', $internalPath);
            
            // We search using the /Items endpoint and filter by path
            $url = "{$this->jellyfinHost}/Items";
            $this->logger->info("Searching for Jellyfin Item ID for path: $normalizedInternalPath (original: $path)");
            
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'X-Emby-Token' => $this->jellyfinApiKey,
                ],
                'query' => [
                    'Recursive' => 'true',
                    'Fields' => 'Path',
                    'StartIndex' => 0,
                    'Limit' => 100,
                ],
                'timeout' => 5,
            ]);

            $data = $response->toArray();
            
            if (isset($data['Items'])) {
                foreach ($data['Items'] as $item) {
                    if (isset($item['Path'])) {
                        $jellyfinItemPath = str_replace('\\', '/', $item['Path']);
                        if ($jellyfinItemPath === $normalizedInternalPath) {
                            return $item['Id'];
                        }
                    }
                }
            }
            
            $this->logger->warning("Could not find Jellyfin Item ID for path: $normalizedInternalPath");
        } catch (\Exception $e) {
            $this->logger->error("Error finding Jellyfin Item ID: " . $e->getMessage());
        }

        return null;
    }
}
