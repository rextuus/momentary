<?php

namespace App\Service\File;

use League\Flysystem\Config;
use League\Flysystem\UrlGeneration\PublicUrlGenerator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

readonly class FlysystemPublicUrlGenerator implements PublicUrlGenerator
{
    public function __construct(
        #[Autowire('%env(FACE_UPLOAD_DIR)%')]
        private string $publicPath
    )
    {
    }

    public function publicUrl(string $path, Config $config): string
    {
        return $this->publicPath . '/' . ltrim($path, '/');
    }
}