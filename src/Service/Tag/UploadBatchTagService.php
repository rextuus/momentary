<?php

declare(strict_types=1);

namespace App\Service\Tag;

use App\Entity\Tag;
use App\Entity\TagCategory;
use App\Entity\Video;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Markiert Videos, die gemeinsam hochgeladen wurden (z. B. aus einem Ordner),
 * über ein Tag in der Kategorie "Upload-Ordner".
 */
final class UploadBatchTagService
{
    private const CATEGORY_NAME = 'Upload-Ordner';
    private const MAX_NAME_LENGTH = 100;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function tagVideo(Video $video, string $batchName): Tag
    {
        $tag = $this->getOrCreateTag($batchName);
        $video->addTag($tag);
        $this->entityManager->flush();

        return $tag;
    }

    private function getOrCreateTag(string $batchName): Tag
    {
        $batchName = mb_substr(trim($batchName), 0, self::MAX_NAME_LENGTH);

        $category = $this->entityManager->getRepository(TagCategory::class)
            ->findOneBy(['name' => self::CATEGORY_NAME]);

        if ($category === null) {
            $category = new TagCategory();
            $category->setName(self::CATEGORY_NAME);
            $this->entityManager->persist($category);
            $this->entityManager->flush();
        }

        $tag = $this->entityManager->getRepository(Tag::class)
            ->findOneBy(['name' => $batchName, 'category' => $category]);

        if ($tag === null) {
            $tag = new Tag();
            $tag->setName($batchName);
            $tag->setCategory($category);
            $this->entityManager->persist($tag);
            $this->entityManager->flush();
        }

        return $tag;
    }
}