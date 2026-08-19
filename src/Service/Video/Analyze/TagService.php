<?php

declare(strict_types=1);

namespace App\Service\Video\Analyze;

use App\Entity\Tag;
use App\Entity\TagCategory;
use App\Entity\VideoScene;
use Doctrine\ORM\EntityManagerInterface;

class TagService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<string, array<string>> $allTagsStructured
     */
    public function assignAiTagsToScene(VideoScene $scene, array $allTagsStructured): void
    {
        foreach ($allTagsStructured as $categoryName => $tags) {
            if (!is_array($tags)) {
                continue;
            }

            $category = $this->entityManager->getRepository(TagCategory::class)->findOneBy(['name' => $categoryName]);
            if (!$category) {
                $category = new TagCategory();
                $category->setName($categoryName);
                $this->entityManager->persist($category);
            }

            foreach ($tags as $tagName) {
                $tagName = trim($tagName);
                if ($tagName === '') {
                    continue;
                }

                $tag = $this->entityManager->getRepository(Tag::class)->findOneBy([
                    'name' => $tagName,
                    'category' => $category
                ]);

                if (!$tag) {
                    $tag = new Tag();
                    $tag->setName($tagName);
                    $tag->setCategory($category);
                    $this->entityManager->persist($tag);
                }

                // Nutzen der neuen Join-Entity Logik mit AI-Flag
                $scene->addSceneTag($tag, isAiGenerated: true);
            }
        }

        $this->entityManager->flush();
    }
}