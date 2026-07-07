<?php

namespace App\Service;

use Onliner\ImgProxy\UrlBuilder;
use Onliner\ImgProxy\Options\Width;
use Onliner\ImgProxy\Options\Height;
use Onliner\ImgProxy\Options\ResizingType;

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

    public function generateUrl(string $sourceUrl, int $width = 300, int $height = 300, string $resizingType = 'fill'): string
    {
        // Cache-Buster entfernen, falls vorhanden, für das imgproxy-Mapping
        $pureSourceUrl = $sourceUrl;
        $queryString = '';
        if (($pos = strpos($sourceUrl, '?')) !== false) {
            $pureSourceUrl = substr($sourceUrl, 0, $pos);
            $queryString = substr($sourceUrl, $pos);
        }

        // Mapping für lokale Pfade: imgproxy sieht /public als Root (siehe compose.yaml)
        // Die Files liegen physisch unter:
        // - Gesichter: public/uploads/faces/video_faces/ -> Mapping: local:///uploads/faces/video_faces/
        // - Thumbnails: public/uploads/thumbnails/ -> Mapping: local:///uploads/thumbnails/
        // - Import-Videos: public/uploads/import/ -> Mapping: local:///uploads/import/

        if (!str_starts_with($pureSourceUrl, 'http://') && !str_starts_with($pureSourceUrl, 'https://') && !str_starts_with($pureSourceUrl, 'local:///')) {
            
            $path = ltrim($pureSourceUrl, '/');

            if (str_starts_with($path, 'video_faces/')) {
                $path = 'uploads/faces/' . $path;
            } elseif (str_starts_with($path, 'video_analyze_')) {
                $path = 'uploads/import/' . $path;
            }
            // Thumbnails (uploads/thumbnails/...) bleiben wie sie sind

            $pureSourceUrl = 'local:///' . $path;
        }

        // Falls wir eine URL haben, die bereits local:/// enthält, aber noch gemappt werden muss (Legacy/Alternativpfade)
        if (str_starts_with($pureSourceUrl, 'local:///video_faces/')) {
            $pureSourceUrl = str_replace('local:///video_faces/', 'local:///uploads/faces/video_faces/', $pureSourceUrl);
        }

        // Wir fügen den Cache-Buster wieder an die Source-URL an, die imgproxy erhält,
        // damit imgproxy selbst seinen Cache umgeht (falls konfiguriert)
        $finalSourceUrl = $pureSourceUrl . $queryString;

        $generatedUrl = $this->publicHost . $this->builder
            ->with(new Width($width), new Height($height), new ResizingType($resizingType))
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
}
