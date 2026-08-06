<?php

namespace App\Service;

use Onliner\ImgProxy\UrlBuilder;
use Onliner\ImgProxy\Options\Width;
use Onliner\ImgProxy\Options\Height;
use Onliner\ImgProxy\Options\ResizingType;
use Onliner\ImgProxy\Options\Blur;

class ImgproxyService
{
    private UrlBuilder $builder;
    private string $publicHost;

    public function __construct(
        string $key,
        string $salt,
        string $publicHost
    ) {
        $this->builder = UrlBuilder::signed($key, $salt);
        $this->publicHost = $publicHost;
    }

    public function generateUrl(string $sourceUrl, int $width = 300, int $height = 300, string $resizingType = 'fill', int $blur = 0): string
    {
        // Cache-Buster entfernen, falls vorhanden, für das imgproxy-Mapping
        $pureSourceUrl = $sourceUrl;
        
        // Falls die URL bereits den publicHost enthält, entfernen wir ihn, um den Pfad zu erhalten
        if (str_starts_with($pureSourceUrl, $this->publicHost)) {
            $pureSourceUrl = str_replace($this->publicHost, '', $pureSourceUrl);
        }

        // Falls es sich bereits um eine Imgproxy-URL handelt, versuchen wir die originale URL zu extrahieren
        if (str_starts_with($pureSourceUrl, '/') && (str_contains($pureSourceUrl, '/w:') || str_contains($pureSourceUrl, '/h:'))) {
             $parts = explode('/', trim($pureSourceUrl, '/'));
             
             // The structure is {signature}/{w:XXX}/{h:XXX}/{rt:XXX}/{encoded_url}
             // So {encoded_url} is the 5th element, or everything after {rt:XXX}.
             
             $encodedUrl = implode('/', array_slice($parts, 4));
             $decodedSourceUrl = $this->base64UrlDecode($encodedUrl);
             
             // Rekursiver Aufruf mit der dekodierten URL, diese wird dann neu signiert
             return $this->generateUrl($decodedSourceUrl, $width, $height, $resizingType, $blur);
        }

        $queryString = '';
        if (($pos = strpos($pureSourceUrl, '?')) !== false) {
            $pureSourceUrl = substr($pureSourceUrl, 0, $pos);
            $queryString = substr($pureSourceUrl, $pos);
        }

        // Mapping für lokale Pfade: imgproxy sieht /public als Root (siehe compose.yaml)
        // Die Files liegen physisch unter:
        // - Gesichter: media/images/faces/video_faces/ -> Mapping: local:///media/images/faces/video_faces/
        // - Import-Videos: media/images/import/ -> Mapping: local:///media/images/import/
        // - Flysystem-Pfade (z.B. SomeName_hash/thumbnails/...): media/images/{path} -> Mapping: local:///media/images/{path}

        if (!str_starts_with($pureSourceUrl, 'http://') && !str_starts_with($pureSourceUrl, 'https://') && !str_starts_with($pureSourceUrl, 'local:///')) {
            
            $path = ltrim($pureSourceUrl, '/');

            if (str_starts_with($path, 'video_faces/')) {
                $path = 'media/images/faces/' . $path;
            } elseif (str_starts_with($path, 'video_analyze_')) {
                $path = 'media/images/import/' . $path;
            } elseif (!str_starts_with($path, 'media/images/')) {
                // Flysystem-Pfade (z.B. SomeName_hash/thumbnails/...) liegen unter media/images/
                $path = 'media/images/' . $path;
            }

            $pureSourceUrl = 'local:///' . $path;
        }

        // Falls wir eine URL haben, die bereits local:/// enthält, aber noch gemappt werden muss (Legacy/Alternativpfade)
        if (str_starts_with($pureSourceUrl, 'local:///video_faces/')) {
            $pureSourceUrl = str_replace('local:///video_faces/', 'local:///media/images/faces/video_faces/', $pureSourceUrl);
        }

        // Wir fügen den Cache-Buster wieder an die Source-URL an, die imgproxy erhält,
        // damit imgproxy selbst seinen Cache umgeht (falls konfiguriert)
        $finalSourceUrl = $pureSourceUrl . $queryString;

        $options = [new Width($width), new Height($height), new ResizingType($resizingType)];
        if ($blur > 0) {
            $options[] = new Blur($blur);
        }

        $generatedUrl = $this->publicHost . $this->builder
            ->with(...$options)
            ->url($finalSourceUrl, 'jpg');

        // Auch an die generierte URL den Cache-Buster hängen für den Browser
        if ($queryString) {
            $generatedUrl .= (str_contains($generatedUrl, '?') ? '&' : '?') . ltrim($queryString, '?');
        }

        return $generatedUrl;
    }

    public function getPublicHost(): string
    {
        return $this->publicHost;
    }

    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
    }
}
