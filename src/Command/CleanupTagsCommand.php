<?php

namespace App\Command;

use App\Entity\Tag;
use App\Entity\TagCategory;
use App\Entity\VideoScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cleanup-tags',
    description: 'Merge duplicate tags and categories',
)]
class CleanupTagsCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->mergeCategories($io);
        $this->mergeTags($io);
        $this->cleanupSceneTagDuplicates($io);

        $this->entityManager->flush();

        $io->success('Cleanup completed.');

        return Command::SUCCESS;
    }

    private function mergeCategories(SymfonyStyle $io): void
    {
        $repository = $this->entityManager->getRepository(TagCategory::class);
        $categories = $repository->findAll();
        $byName = [];

        foreach ($categories as $category) {
            $name = strtolower($category->getName());
            if (!isset($byName[$name])) {
                $byName[$name] = [];
            }
            $byName[$name][] = $category;
        }

        foreach ($byName as $name => $cats) {
            if (count($cats) > 1) {
                $io->note("Merging categories with name: $name");
                $keptCategory = array_shift($cats);
                foreach ($cats as $cat) {
                    foreach ($cat->getTags() as $tag) {
                        $tag->setCategory($keptCategory);
                    }
                    $this->entityManager->remove($cat);
                }
            }
        }
    }

    private function mergeTags(SymfonyStyle $io): void
    {
        $repository = $this->entityManager->getRepository(Tag::class);
        $tags = $repository->findAll();
        $byNameAndCategory = [];

        foreach ($tags as $tag) {
            $name = strtolower($tag->getName());
            $catId = $tag->getCategory()?->getId() ?? 'no_cat';
            $key = $name . '_' . $catId;
            
            if (!isset($byNameAndCategory[$key])) {
                $byNameAndCategory[$key] = [];
            }
            $byNameAndCategory[$key][] = $tag;
        }

        foreach ($byNameAndCategory as $key => $tagList) {
            if (count($tagList) > 1) {
                $io->note("Merging tags with key: $key");
                $keptTag = array_shift($tagList);
                
                foreach ($tagList as $tag) {
                    foreach ($tag->getScenes() as $scene) {
                        $scene->removeTag($tag);
                        $scene->addTag($keptTag);
                    }
                    $this->entityManager->remove($tag);
                }
            }
        }
    }

    private function cleanupSceneTagDuplicates(SymfonyStyle $io): void
    {
        $scenes = $this->entityManager->getRepository(VideoScene::class)->findAll();
        foreach ($scenes as $scene) {
            $tags = $scene->getTags();
            $seenTags = [];
            $duplicates = [];
            foreach ($tags as $tag) {
                $id = $tag->getId();
                if (isset($seenTags[$id])) {
                    $duplicates[] = $tag;
                } else {
                    $seenTags[$id] = $tag;
                }
            }
            
            if (count($duplicates) > 0) {
                $io->note("Removing " . count($duplicates) . " duplicate tag assignments in scene " . $scene->getId());
                foreach ($duplicates as $duplicate) {
                    $scene->removeTag($duplicate);
                }
            }
        }
    }
}
