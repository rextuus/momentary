<?php

namespace App\Service;

use App\Entity\Video;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\String\Slugger\SluggerInterface;

class VideoFileService
{
    public function __construct(
        private readonly FilesystemOperator $filesystem,
        private readonly EntityManagerInterface $entityManager,
        private readonly SluggerInterface $slugger,
        #[Autowire('%kernel.project_dir%')]
        string $projectDir
    ) {
        $this->basePath = $projectDir . '/' . PathConstants::MEDIA_IMAGES;
    }

    private string $basePath;

    public function getVideoDirectory(Video $video, string $subFolder): string
    {
        if (!$video->getDirectoryHash()) {
            $video->setDirectoryHash(bin2hex(random_bytes(8)));
            $this->entityManager->flush();
        }

        $folderName = $this->slugger->slug($video->getTitle()) . '_' . $video->getDirectoryHash();
        return $folderName . '/' . $subFolder;
    }

    public function getAbsolutePath(string $relativeFilePath): string
    {
        return $this->basePath . '/' . $relativeFilePath;
    }

    public function getFilesystem(): FilesystemOperator
    {
        return $this->filesystem;
    }
}
