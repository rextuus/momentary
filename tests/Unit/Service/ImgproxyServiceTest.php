<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ImgproxyService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImgproxyServiceTest extends TestCase
{
    private const string HOST = 'http://imgproxy.test';

    private ImgproxyService $service;

    protected function setUp(): void
    {
        $this->service = new ImgproxyService(
            '943f17d3b15f94d936173d120a1b2c3d',
            '5d7b5f2a1b9c3d4e5f6a7b8c9d0e1f2a',
            self::HOST,
        );
    }

    public function testRelativePathIsServedFromLocalStorage(): void
    {
        $url = $this->service->generateUrl('images/a.jpg', 160, 90);

        self::assertStringStartsWith(self::HOST . '/', $url);
        self::assertStringContainsString('/w:160/h:90/rt:fill/', $url);
        self::assertSame('local:///images/a.jpg', $this->decodeSource($url));
    }

    public function testLocalSchemeAndRelativePathProduceTheSameUrl(): void
    {
        self::assertSame(
            $this->service->generateUrl('images/a.jpg'),
            $this->service->generateUrl('local:///images/a.jpg'),
        );
    }

    public function testBlurIsOnlyAddedWhenRequested(): void
    {
        self::assertStringNotContainsString('/bl:', $this->service->generateUrl('images/a.jpg'));
        self::assertStringContainsString('/bl:5/', $this->service->generateUrl('images/a.jpg', blur: 5));
    }

    public function testSignatureDependsOnOptions(): void
    {
        self::assertNotSame(
            $this->signature($this->service->generateUrl('images/a.jpg', 100, 100)),
            $this->signature($this->service->generateUrl('images/a.jpg', 200, 100)),
        );
    }

    public function testRemoteUrlKeepsItsQueryString(): void
    {
        $url = $this->service->generateUrl('https://example.com/x.jpg?v=2', 10, 10);

        self::assertSame('https://example.com/x.jpg?v=2', $this->decodeSource($url));
        self::assertStringEndsWith('?v=2', $url);
    }

    public function testPublicHostPrefixIsStripped(): void
    {
        self::assertSame(
            $this->service->generateUrl('/images/a.jpg'),
            $this->service->generateUrl(self::HOST . '/images/a.jpg'),
        );
    }

    /**
     * @return iterable<string, array{string, int, int, string, int}>
     */
    public static function resignCases(): iterable
    {
        yield 'lokales Bild, neue Größe' => ['images/a.jpg', 320, 180, 'fit', 0];
        yield 'lokales Bild, nachträglich unscharf' => ['images/videos/1/thumbnails/scene_12.jpg', 160, 90, 'fill', 5];
        yield 'externes Bild mit Query' => ['https://example.com/x.jpg?v=2', 160, 90, 'fill', 5];
    }

    /**
     * Die Such-API signiert bereits signierte Thumbnail-URLs mit Blur neu.
     */
    #[DataProvider('resignCases')]
    public function testResigningASignedUrlEqualsSigningTheOriginalSource(
        string $source,
        int $width,
        int $height,
        string $resizing,
        int $blur,
    ): void {
        $alreadySigned = $this->service->generateUrl($source, 300, 300, 'fill', 0);
        $alreadySignedAndBlurred = $this->service->generateUrl($source, 300, 300, 'fill', 3);
        $expected = $this->service->generateUrl($source, $width, $height, $resizing, $blur);

        self::assertSame($expected, $this->service->generateUrl($alreadySigned, $width, $height, $resizing, $blur));
        self::assertSame($expected, $this->service->generateUrl(
            $alreadySignedAndBlurred,
            $width,
            $height,
            $resizing,
            $blur,
        ));
    }

    private function signature(string $url): string
    {
        return explode('/', substr($url, \strlen(self::HOST) + 1))[0];
    }

    private function decodeSource(string $url): string
    {
        $path = parse_url($url, \PHP_URL_PATH);
        $segments = array_filter(
            \array_slice(explode('/', trim((string) $path, '/')), 1),
            static fn(string $s): bool => !str_contains($s, ':'),
        );
        $encoded = preg_replace('/\.[a-z0-9]+$/i', '', implode('', $segments));

        return base64_decode(strtr((string) $encoded, '-_', '+/'), strict: true);
    }
}
