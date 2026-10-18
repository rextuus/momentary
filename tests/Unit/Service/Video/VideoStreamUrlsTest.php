<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Video;

use App\Entity\Video;
use App\Service\Video\VideoStreamUrls;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class VideoStreamUrlsTest extends TestCase
{
    private function urls(?string $publicUrl): VideoStreamUrls
    {
        $generator = $this->createStub(UrlGeneratorInterface::class);
        $generator->method('generate')->willReturn('/stream/video/7');

        return new VideoStreamUrls(new UriSigner('geheim'), $generator, $publicUrl);
    }

    private function video(): Video
    {
        $video = new Video();
        new \ReflectionProperty(Video::class, 'id')->setValue($video, 7);

        return $video;
    }

    public function testUsesThePublicAddressAndStaysValidBehindAProxy(): void
    {
        $urls = $this->urls('https://momentary.example.de/');
        // Der Hub fragt intern als http://app an
        $stream = $urls->create($this->video(), Request::create('http://app/api/videos/7/stream-url'));

        self::assertStringStartsWith('https://momentary.example.de/stream/video/7?', $stream['url']);
        // Beim Abruf sieht das Backend womöglich eine andere Adresse (Reverse Proxy)
        $path = (string) substr($stream['url'], \strlen('https://momentary.example.de'));
        self::assertTrue($urls->isValid(Request::create('http://app:80' . $path)));
        self::assertFalse($urls->isValid(Request::create('http://app:80' . str_replace('/7?', '/8?', $path))));
    }

    public function testFallsBackToTheRequestAddress(): void
    {
        $stream = $this->urls('')->create(
            $this->video(),
            Request::create('http://192.168.178.56:8088/api/videos/7/stream-url'),
        );

        self::assertStringStartsWith('http://192.168.178.56:8088/stream/video/7?', $stream['url']);
    }
}
