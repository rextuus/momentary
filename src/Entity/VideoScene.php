<?php

namespace App\Entity;

use App\Repository\VideoSceneRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VideoSceneRepository::class)]
class VideoScene
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'scenes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Video $video = null;

    #[ORM\Column]
    private int $sceneNumber;

    #[ORM\Column]
    private float $startSeconds;

    #[ORM\Column]
    private float $endSeconds;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVideo(): ?Video
    {
        return $this->video;
    }

    public function setVideo(?Video $video): self
    {
        $this->video = $video;
        return $this;
    }

    public function getSceneNumber(): int
    {
        return $this->sceneNumber;
    }

    public function setSceneNumber(int $sceneNumber): self
    {
        $this->sceneNumber = $sceneNumber;
        return $this;
    }

    public function getStartSeconds(): float
    {
        return $this->startSeconds;
    }

    public function setStartSeconds(float $startSeconds): self
    {
        $this->startSeconds = $startSeconds;
        return $this;
    }

    public function getEndSeconds(): float
    {
        return $this->endSeconds;
    }

    public function setEndSeconds(float $endSeconds): self
    {
        $this->endSeconds = $endSeconds;
        return $this;
    }
}
