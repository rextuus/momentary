<?php

namespace App\Service;

use Onliner\ImgProxy\UrlBuilder;
use Onliner\ImgProxy\Options\Width;
use Onliner\ImgProxy\Options\Height;
use Onliner\ImgProxy\Options\ResizingType;
use Onliner\ImgProxy\Options\Blur;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class ImgproxyService
{
    private UrlBuilder $builder;
    private string $publicHost;

    public function __construct(
        #[Autowire('%imgproxy_key%')]
        string $key,
        #[Autowire('%imgproxy_salt%')]
        string $salt,
        #[Autowire('%imgproxy_public_host%')]
        string $publicHost
    ) {
        $this->builder = UrlBuilder::signed($key, $salt);
        $this->publicHost = $publicHost;
    }

    public function generateUrl(string $sourceUrl, int $width = 300, int $height = 300, string $resizingType = 'fill', int $blur = 0): string
    {
        $pureSourceUrl = $sourceUrl;

        // Falls die URL bereits den publicHost enthält, entfernen wir ihn
        if (str_starts_with($pureSourceUrl, $this->publicHost)) {
            $pureSourceUrl = str_replace($this->publicHost, '', $pureSourceUrl);
        }

        // Falls es sich bereits um eine signierte Imgproxy-URL handelt, dekodieren wir sie für den Re-Sign-Fall
        if (str_starts_with($pureSourceUrl, '/') && (str_contains($pureSourceUrl, '/w:') || str_contains($pureSourceUrl, '/h:'))) {
            $parts = explode('/', trim($pureSourceUrl, '/'));
            $encodedUrl = implode('/', array_slice($parts, 4));
            $decodedSourceUrl = $this->base64UrlDecode($encodedUrl);

            return $this->generateUrl($decodedSourceUrl, $width, $height, $resizingType, $blur);
        }

        $queryString = '';
        if (($pos = strpos($pureSourceUrl, '?')) !== false) {
            $queryString = substr($pureSourceUrl, $pos);
            $pureSourceUrl = substr($pureSourceUrl, 0, $pos);
        }

        // Falls kein Schema angegeben ist, standardmäßig als lokales File behandeln
        if (!str_starts_with($pureSourceUrl, 'http://') && !str_starts_with($pureSourceUrl, 'https://') && !str_starts_with($pureSourceUrl, 'local:///')) {
            $pureSourceUrl = 'local:///' . ltrim($pureSourceUrl, '/');
        }

        $finalSourceUrl = $pureSourceUrl . $queryString;

        $options = [new Width($width), new Height($height), new ResizingType($resizingType)];
        if ($blur > 0) {
            $options[] = new Blur($blur);
        }

        $generatedUrl = $this->publicHost . $this->builder
                ->with(...$options)
                ->url($finalSourceUrl, 'jpg');

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
