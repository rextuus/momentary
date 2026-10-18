<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\File;
use App\Service\ImgproxyService;
use App\Twig\Extension\ImgproxyExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImgproxyExtensionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function paths(): iterable
    {
        yield 'Gesicht (neu)' => ['images/faces/9/a.jpg', 'local:///images/faces/9/a.jpg'];
        yield 'Gesicht (alt, ohne images/)' => ['faces/9/a.jpg', 'local:///images/faces/9/a.jpg'];
        yield 'Foto' => ['photos/11/thumbnail.jpg', 'local:///photos/11/thumbnail.jpg'];
        yield 'Video' => ['videos/4/thumb.jpg', 'local:///videos/4/thumb.jpg'];
    }

    #[DataProvider('paths')]
    public function testResolvesStoragePaths(string $relativePath, string $expectedSource): void
    {
        $service = new ImgproxyService(
            '943f17d3b15f94d936173d120a1b2c3d',
            '5d7b5f2a1b9c3d4e5f6a7b8c9d0e1f2a',
            'http://imgproxy.test',
        );
        $file = new File();
        $file->setRelativePath($relativePath);

        $url = new ImgproxyExtension($service)->generateUrl($file);

        self::assertSame($service->generateUrl($expectedSource), $url);
    }
}
