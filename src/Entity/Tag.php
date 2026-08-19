<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use App\Repository\TagRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: TagRepository::class)]
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/tags',
            normalizationContext: ['groups' => ['tag:read']]
        ),
        new Get(
            normalizationContext: ['groups' => ['tag:read']]
        )
    ]
)]
#[ApiFilter(SearchFilter::class, properties: ['name' => 'partial'])]
class Tag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['tag:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['tag:read'])]
    private ?string $name = null;

    #[ORM\ManyToOne(inversedBy: 'tags')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['tag:read'])]
    private ?TagCategory $category = null;

    /**
     * @var Collection<int, VideoSceneTag>
     */
    #[ORM\OneToMany(targetEntity: VideoSceneTag::class, mappedBy: 'tag', orphanRemoval: true)]
    private Collection $sceneTags;

    public function __construct()
    {
        $this->sceneTags = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getCategory(): ?TagCategory
    {
        return $this->category;
    }

    public function setCategory(?TagCategory $category): static
    {
        $this->category = $category;
        return $this;
    }

    /**
     * @return Collection<int, VideoSceneTag>
     */
    public function getSceneTags(): Collection
    {
        return $this->sceneTags;
    }

    public function addSceneTag(VideoSceneTag $sceneTag): static
    {
        if (!$this->sceneTags->contains($sceneTag)) {
            $this->sceneTags->add($sceneTag);
            $sceneTag->setTag($this);
        }

        return $this;
    }

    public function removeSceneTag(VideoSceneTag $sceneTag): static
    {
        if ($this->sceneTags->removeElement($sceneTag)) {
            if ($sceneTag->getTag() === $this) {
                $sceneTag->setTag(null);
            }
        }

        return $this;
    }
}