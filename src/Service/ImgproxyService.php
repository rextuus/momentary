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
        if (!str_starts_with($sourceUrl, 'http://') && !str_starts_with($sourceUrl, 'https://') && !str_starts_with($sourceUrl, 'local:///')) {
            $sourceUrl = 'local:///' . ltrim($sourceUrl, '/');
        }

        return $this->publicHost . $this->builder
            ->with(new Width($width), new Height($height), new ResizingType($resizingType))
            ->url($sourceUrl, 'jpg');
    }

    public function getPublicHost(): string
    {
        return $this->publicHost;
    }
}
