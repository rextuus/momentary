<?php

namespace App\Entity;

use App\Repository\VideoChapterRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: VideoChapterRepository::class)]
class VideoChapter
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['video:detail'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'chapters')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Video $video = null;

    #[ORM\Column(length: 255)]
    #[Groups(['video:detail'])]
    private ?string $title = null;

    #[ORM\Column]
    #[Groups(['video:detail'])]
    private ?float $startSeconds = null;

    #[ORM\Column]
    #[Groups(['video:detail'])]
    private ?float $endSeconds = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['video:detail'])]
    private ?string $description = null;

    public function __construct()
    {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVideo(): ?Video
    {
        return $this->video;
    }

    public function setVideo(?Video $video): static
    {
        $this->video = $video;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getStartSeconds(): ?float
    {
        return $this->startSeconds;
    }

    public function setStartSeconds(float $startSeconds): static
    {
        $this->startSeconds = $startSeconds;

        return $this;
    }

    public function getEndSeconds(): ?float
    {
        return $this->endSeconds;
    }

    public function setEndSeconds(float $endSeconds): static
    {
        $this->endSeconds = $endSeconds;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }
}
