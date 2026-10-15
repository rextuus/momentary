<?php

namespace App\Service\Jellyfin;

use App\Service\PathConstants;
use App\Service\VideoFileService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class JellyfinUploadService
{
    private string $uploadDir;
    private string $jellyfinHost;
    private ?string $jellyfinApiKey;

    public function __construct(
        #[Autowire(env: 'JELLYFIN_HOST')]
        string $jellyfinHost,
        #[Autowire(env: 'JELLYFIN_API_KEY')]
        ?string $jellyfinApiKey,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly VideoFileService $videoFileService
    ) {
        // Wir nutzen den VideoFileService, um den absoluten Pfad für das Jellyfin-Upload-Verzeichnis aufzulösen
        $relativeUploadDir = PathConstants::JELLYFIN_UPLOADS;
        $this->uploadDir = $this->videoFileService->getAbsolutePath($relativeUploadDir);
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
            // Versuche, gezielt die Bibliothek zu aktualisieren, die /uploads enthält
            $urlFolders = "{$this->jellyfinHost}/Library/VirtualFolders";
            $responseFolders = $this->httpClient->request('GET', $urlFolders, [
                'headers' => ['X-Emby-Token' => $this->jellyfinApiKey],
                'timeout' => 5,
            ]);
            
            $folders = $responseFolders->toArray();
            $libraryId = null;

            foreach ($folders as $folder) {
                // Jellyfin speichert Locations oft mit dem Pfad wie er im Container konfiguriert ist
                if (isset($folder['Locations']) && in_array('/uploads', $folder['Locations'])) {
                    $libraryId = $folder['Id'] ?? $folder['id'] ?? null;
                    break;
                }
            }

            if ($libraryId) {
                $url = "{$this->jellyfinHost}/Library/VirtualFolders/Refresh?id={$libraryId}";
                $this->logger->info("Requesting Jellyfin refresh for library $libraryId: $url");
            } else {
                $url = "{$this->jellyfinHost}/Library/Refresh";
                $this->logger->info("Specific library not found, requesting global Jellyfin refresh: $url");
            }

            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'X-Emby-Token' => $this->jellyfinApiKey,
                ],
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();
            $this->logger->info("Jellyfin API responded with status code: $statusCode");

            if ($statusCode === 204 || $statusCode === 200) {
                $this->logger->info("Triggered Jellyfin library refresh.");
            } else {
                $this->logger->error("Jellyfin refresh returned status: " . $statusCode);
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
            // Wir suchen nach dem Pfad, wie Jellyfin ihn sieht.
            // Jellyfin speichert den Pfad relativ zur Mediathek-Root, also inkl. "/uploads/".
            $internalPath = '/uploads/' . ltrim(basename($path), '/');
            
            $normalizedInternalPath = str_replace('\\', '/', $internalPath);
            
            $url = "{$this->jellyfinHost}/Items";
            $this->logger->info("Searching for Jellyfin Item ID for path: $normalizedInternalPath (internal: $internalPath, basename: " . basename($path) . ", original: $path)");

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
                        $this->logger->info("Jellyfin Item: " . $item['Id'] . " Path: " . $jellyfinItemPath . " Search: " . $normalizedInternalPath);
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
