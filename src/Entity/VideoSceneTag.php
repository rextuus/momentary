<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
#[ORM\Table(name: 'video_scene_tag')]
class VideoSceneTag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['video:detail'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: VideoScene::class, inversedBy: 'sceneTags')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?VideoScene $videoScene = null;

    #[ORM\ManyToOne(targetEntity: Tag::class, inversedBy: 'sceneTags')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['video:detail'])]
    private ?Tag $tag = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['video:detail'])]
    private bool $isAiGenerated = false;

    #[ORM\Column(type: 'float', nullable: true)]
    #[Groups(['video:detail'])]
    private ?float $confidence = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVideoScene(): ?VideoScene
    {
        return $this->videoScene;
    }

    public function setVideoScene(?VideoScene $videoScene): static
    {
        $this->videoScene = $videoScene;
        return $this;
    }

    public function getTag(): ?Tag
    {
        return $this->tag;
    }

    public function setTag(?Tag $tag): static
    {
        $this->tag = $tag;
        return $this;
    }

    public function isAiGenerated(): bool
    {
        return $this->isAiGenerated;
    }

    public function setIsAiGenerated(bool $isAiGenerated): static
    {
        $this->isAiGenerated = $isAiGenerated;
        return $this;
    }

    public function getConfidence(): ?float
    {
        return $this->confidence;
    }

    public function setConfidence(?float $confidence): static
    {
        $this->confidence = $confidence;
        return $this;
    }
}