<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Imgproxy\RelativeCrop;
use Onliner\ImgProxy\Options\Blur;
use Onliner\ImgProxy\Options\Height;
use Onliner\ImgProxy\Options\ResizingType;
use Onliner\ImgProxy\Options\Width;
use Onliner\ImgProxy\UrlBuilder;
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
        string $publicHost,
    ) {
        $this->builder = UrlBuilder::signed($key, $salt);
        $this->publicHost = $publicHost;
    }

    public function generateUrl(
        string $sourceUrl,
        int $width = 300,
        int $height = 300,
        string $resizingType = 'fill',
        int $blur = 0,
    ): string {
        $pureSourceUrl = $sourceUrl;

        // Falls die URL bereits den publicHost enthält, entfernen wir ihn
        if (str_starts_with($pureSourceUrl, $this->publicHost)) {
            $pureSourceUrl = str_replace($this->publicHost, '', $pureSourceUrl);
        }

        // Falls es sich bereits um eine signierte Imgproxy-URL handelt, dekodieren wir sie für den Re-Sign-Fall
        if (
            str_starts_with($pureSourceUrl, '/')
            && (str_contains($pureSourceUrl, '/w:') || str_contains($pureSourceUrl, '/h:'))
        ) {
            // Aufbau: /{signatur}/{option:wert}/.../{base64-quelle in Segmenten}.{ext}[?query]
            // Die Query steckt bereits in der kodierten Quelle.
            $path = strtok($pureSourceUrl, '?');
            $segments = array_slice(explode('/', trim($path, '/')), 1);
            $encodedSegments = array_filter(
                $segments,
                static fn(string $segment): bool => !str_contains($segment, ':'),
            );
            $encodedUrl = preg_replace('/\.[a-z0-9]+$/i', '', implode('', $encodedSegments)) ?? '';
            $decodedSourceUrl = $this->base64UrlDecode($encodedUrl);

            return $this->generateUrl($decodedSourceUrl, $width, $height, $resizingType, $blur);
        }

        $queryString = '';
        if (($pos = strpos($pureSourceUrl, '?')) !== false) {
            $queryString = substr($pureSourceUrl, $pos);
            $pureSourceUrl = substr($pureSourceUrl, 0, $pos);
        }

        // Falls kein Schema angegeben ist, standardmäßig als lokales File behandeln
        if (
            !str_starts_with($pureSourceUrl, 'http://')
            && !str_starts_with($pureSourceUrl, 'https://')
            && !str_starts_with($pureSourceUrl, 'local:///')
        ) {
            $pureSourceUrl = 'local:///' . ltrim($pureSourceUrl, '/');
        }

        $finalSourceUrl = $pureSourceUrl . $queryString;

        $options = [new Width($width), new Height($height), new ResizingType($resizingType)];
        if ($blur > 0) {
            $options[] = new Blur($blur);
        }

        $generatedUrl = $this->publicHost . $this->builder->with(...$options)->url($finalSourceUrl, 'jpg');

        if ($queryString) {
            $generatedUrl .= (str_contains($generatedUrl, '?') ? '&' : '?') . ltrim($queryString, '?');
        }

        return $generatedUrl;
    }

    /**
     * Ausschnitt um ein Gesicht: $padding = wie viel größer als die Bounding-Box (1 = nur das Gesicht).
     *
     * @param array<string, int|float> $boundingBox Rekognition-Box mit relativen Werten (Left, Top, Width, Height)
     */
    public function generateFaceCropUrl(
        string $sourcePath,
        array $boundingBox,
        int $size = 300,
        float $padding = 1.8,
    ): string {
        $width = (float) ($boundingBox['Width'] ?? 0.0);
        $height = (float) ($boundingBox['Height'] ?? 0.0);
        if ($width <= 0.0 || $height <= 0.0) {
            return $this->generateUrl($sourcePath, $size, $size);
        }

        $crop = new RelativeCrop(
            min(0.9999, $width * $padding),
            min(0.9999, $height * $padding),
            min(1.0, max(0.0, (float) ($boundingBox['Left'] ?? 0.0) + ($width / 2))),
            min(1.0, max(0.0, (float) ($boundingBox['Top'] ?? 0.0) + ($height / 2))),
        );
        $source = 'local:///' . ltrim($sourcePath, '/');

        return (
            $this->publicHost
            . $this->builder->with($crop, new Width($size), new Height($size), new ResizingType('fill'))->url(
                $source,
                'jpg',
            )
        );
    }

    public function getPublicHost(): string
    {
        return $this->publicHost;
    }

    private function base64UrlDecode(string $data): string
    {
        $decoded = base64_decode(
            strtr($data, '-_', '+/') . str_repeat('=', 3 - ((3 + strlen($data)) % 4)),
            strict: true,
        );
        if ($decoded === false) {
            throw new \InvalidArgumentException('Signed imgproxy URL contains an invalid source segment.');
        }

        return $decoded;
    }
}
