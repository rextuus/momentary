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

        // Falls wir eine URL haben, die bereits local:/// enthält, aber noch gemappt werden muss
        if (str_starts_with($pureSourceUrl, 'local:///video_faces/')) {
            $pureSourceUrl = str_replace('local:///video_faces/', 'local:///uploads/faces/video_faces/', $pureSourceUrl);
        }

        if (str_starts_with($pureSourceUrl, 'local:///tmp/video_analyze_')) {
            $pureSourceUrl = str_replace('local:///tmp/', 'local:///uploads/import/', $pureSourceUrl);
        }

        if (!str_starts_with($pureSourceUrl, 'http://') && !str_starts_with($pureSourceUrl, 'https://') && !str_starts_with($pureSourceUrl, 'local:///')) {
            // Mapping für lokale Pfade: imgproxy sieht /public als Root.
            // Die Files liegen aber unter /public/uploads/faces/...
            if (str_starts_with($pureSourceUrl, 'video_faces/')) {
                $pureSourceUrl = 'uploads/faces/' . $pureSourceUrl;
            }

            if (str_starts_with($pureSourceUrl, 'video_analyze_')) {
                $pureSourceUrl = 'uploads/import/' . $pureSourceUrl;
            }

            if ($pureSourceUrl === 'defaults/video-placeholder.jpg') {
                $pureSourceUrl = 'defaults/video-placeholder.jpg'; // Just for clarity, it stays the same
            }

            if (str_starts_with($pureSourceUrl, 'uploads/thumbnails/')) {
                // thumbnailPath in der Datenbank ist bereits uploads/thumbnails/video_X.jpg
            }

            $pureSourceUrl = 'local:///' . ltrim($pureSourceUrl, '/');
        }

        // Wir fügen den Cache-Buster wieder an die Source-URL an, die imgproxy erhält,
        // damit imgproxy selbst seinen Cache umgeht (falls konfiguriert)
        // ODER wir lassen ihn nur an der finalen URL.
        // Meistens reicht es, wenn die Source-URL für imgproxy anders aussieht.
        $finalSourceUrl = $pureSourceUrl . $queryString;

        return $this->publicHost . $this->builder
            ->with(new Width($width), new Height($height), new ResizingType($resizingType))
            ->url($finalSourceUrl, 'jpg');
    }

    public function getPublicHost(): string
    {
        return $this->publicHost;
    }
}
