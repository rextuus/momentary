<?php

namespace App\Twig\Components;

use App\Entity\Tag;
use App\Entity\TagCategory;
use App\Repository\TagCategoryRepository;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class TagManagerComponent
{
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public string $newCategoryName = '';

    #[LiveProp(writable: true)]
    public string $newTagName = '';

    #[LiveProp(writable: true)]
    public ?int $selectedCategoryId = null;

    #[LiveProp(writable: true)]
    public ?int $editingCategoryId = null;

    #[LiveProp(writable: true)]
    public string $editingCategoryName = '';

    #[LiveProp(writable: true)]
    public ?int $editingTagId = null;

    #[LiveProp(writable: true)]
    public string $editingTagName = '';

    public function __construct(
        private TagCategoryRepository $categoryRepository,
        private TagRepository $tagRepository,
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * @return array<TagCategory>
     */
    public function getCategories(): array
    {
        return $this->categoryRepository->findAll();
    }

    #[LiveAction]
    public function addCategory(): void
    {
        if (empty($this->newCategoryName)) {
            return;
        }

        $category = new TagCategory();
        $category->setName($this->newCategoryName);
        $category->setColor('#' . str_pad(dechex(mt_rand(0, 0xFFFFFF)), 6, '0', STR_PAD_LEFT));

        $this->entityManager->persist($category);
        $this->entityManager->flush();

        $this->newCategoryName = '';
    }

    #[LiveAction]
    public function addTag(): void
    {
        if (empty($this->newTagName) || !$this->selectedCategoryId) {
            return;
        }

        $category = $this->categoryRepository->find($this->selectedCategoryId);
        if (!$category) {
            return;
        }

        $tag = new Tag();
        $tag->setName($this->newTagName);
        $tag->setCategory($category);

        $this->entityManager->persist($tag);
        $this->entityManager->flush();

        $this->newTagName = '';
    }

    #[LiveAction]
    public function deleteCategory(#[LiveArg] int $id): void
    {
        $category = $this->categoryRepository->find($id);
        if ($category) {
            $this->entityManager->remove($category);
            $this->entityManager->flush();
        }
    }

    #[LiveAction]
    public function deleteTag(#[LiveArg] int $id): void
    {
        $tag = $this->tagRepository->find($id);
        if ($tag) {
            $this->entityManager->remove($tag);
            $this->entityManager->flush();
        }
    }

    #[LiveAction]
    public function startEditCategory(#[LiveArg] int $id): void
    {
        $category = $this->categoryRepository->find($id);
        if ($category) {
            $this->editingCategoryId = $id;
            $this->editingCategoryName = $category->getName();
        }
    }

    #[LiveAction]
    public function saveCategory(): void
    {
        if (!$this->editingCategoryId) return;

        $category = $this->categoryRepository->find($this->editingCategoryId);
        if ($category) {
            $category->setName($this->editingCategoryName);
            $this->entityManager->flush();
        }
        $this->editingCategoryId = null;
    }

    #[LiveAction]
    public function startEditTag(#[LiveArg] int $id): void
    {
        $tag = $this->tagRepository->find($id);
        if ($tag) {
            $this->editingTagId = $id;
            $this->editingTagName = $tag->getName();
        }
    }

    #[LiveAction]
    public function saveTag(): void
    {
        if (!$this->editingTagId) return;

        $tag = $this->tagRepository->find($this->editingTagId);
        if ($tag) {
            $tag->setName($this->editingTagName);
            $this->entityManager->flush();
        }
        $this->editingTagId = null;
    }

    #[LiveAction]
    public function cancelEdit(): void
    {
        $this->editingCategoryId = null;
        $this->editingTagId = null;
    }
}
