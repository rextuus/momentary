<?php

namespace App\Command\Admin;

use App\Entity\Tag;
use App\Entity\TagCategory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:tags:init',
    description: 'Initialisiert Default-Kategorien und Tags für Videos.',
)]
class InitTagsCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

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
                $io->note(sprintf('Kategorie "%s" wurde erstellt.', $categoryName));
            }

            foreach ($config['tags'] as $tagName) {
                $tag = $this->entityManager->getRepository(Tag::class)->findOneBy(['name' => $tagName, 'category' => $category]);
                if (!$tag) {
                    $tag = new Tag();
                    $tag->setName($tagName);
                    $tag->setCategory($category);
                    $this->entityManager->persist($tag);
                    $io->note(sprintf('Tag "%s" in Kategorie "%s" wurde erstellt.', $tagName, $categoryName));
                }
            }
        }

        $this->entityManager->flush();
        $io->success('Tags und Kategorien wurden erfolgreich initialisiert.');

        return Command::SUCCESS;
    }
}
