<?php

namespace App\Service\Tag;

use App\Entity\Tag;
use App\Entity\TagCategory;
use Doctrine\ORM\EntityManagerInterface;

class TagInitializer
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    public function initialize(): void
    {
        $data = [
            'Metadata' => [
                'color' => '#9b59b6',
                'tags' => ['Smartphone', 'Camera', 'Actioncam', 'Drohne', 'Hochkant', 'Querformat']
            ],
        ];

        foreach ($data as $categoryName => $config) {
            $category = $this->entityManager->getRepository(TagCategory::class)->findOneBy(['name' => $categoryName]);
            if (!$category) {
                $category = new TagCategory();
                $category->setName($categoryName);
                $category->setColor($config['color']);
                $this->entityManager->persist($category);
            }

            foreach ($config['tags'] as $tagName) {
                $tag = $this->entityManager->getRepository(Tag::class)->findOneBy(['name' => $tagName, 'category' => $category]);
                if (!$tag) {
                    $tag = new Tag();
                    $tag->setName($tagName);
                    $tag->setCategory($category);
                    $this->entityManager->persist($tag);
                }
            }
        }

        $this->entityManager->flush();
    }
}
