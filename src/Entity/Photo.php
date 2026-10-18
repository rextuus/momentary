<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PhotoStatus;
use App\Repository\PhotoRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Ein Foto. Das Original bleibt unverändert; für Anzeige und Analyse gibt es eine gedrehte, verkleinerte JPEG-Fassung
 * (auch bei HEIC-Originalen vom iPhone).
 */
#[ORM\Entity(repositoryClass: PhotoRepository::class)]
#[ORM\Table(name: 'photo')]
class Photo extends Medium
{
    #[ORM\ManyToOne(targetEntity: File::class, cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?File $sourceFile = null;

    /** JPEG, richtig gedreht, längste Seite höchstens 2560 px */
    #[ORM\ManyToOne(targetEntity: File::class, cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?File $displayFile = null;

    #[ORM\ManyToOne(targetEntity: File::class, cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?File $thumbnailFile = null;

    /** SHA-256 des Originals, um doppelte Importe zu erkennen */
    #[ORM\Column(length: 64, nullable: true, unique: true)]
    private ?string $fileHash = null;

    #[ORM\Column(length: 16, enumType: PhotoStatus::class)]
    #[Groups(['photo:list'])]
    private PhotoStatus $status = PhotoStatus::QUEUED;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(nullable: true)]
    private ?int $width = null;

    #[ORM\Column(nullable: true)]
    private ?int $height = null;

    #[ORM\Column(nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(nullable: true)]
    private ?float $longitude = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $camera = null;

    public function getType(): string
    {
        return 'photo';
    }

    public function getPreviewFile(): ?File
    {
        return $this->thumbnailFile ?? $this->displayFile;
    }

    public function getSourceFile(): ?File
    {
        return $this->sourceFile;
    }

    public function setSourceFile(?File $sourceFile): static
    {
        $this->sourceFile = $sourceFile;

        return $this;
    }

    public function getDisplayFile(): ?File
    {
        return $this->displayFile;
    }

    public function setDisplayFile(?File $displayFile): static
    {
        $this->displayFile = $displayFile;

        return $this;
    }

    public function getThumbnailFile(): ?File
    {
        return $this->thumbnailFile;
    }

    public function setThumbnailFile(?File $thumbnailFile): static
    {
        $this->thumbnailFile = $thumbnailFile;

        return $this;
    }

    public function getFileHash(): ?string
    {
        return $this->fileHash;
    }

    public function setFileHash(?string $fileHash): static
    {
        $this->fileHash = $fileHash;

        return $this;
    }

    public function getStatus(): PhotoStatus
    {
        return $this->status;
    }

    public function setStatus(PhotoStatus $status): static
    {
        $this->status = $status;
        if ($status !== PhotoStatus::ERROR) {
            $this->errorMessage = null;
        }

        return $this;
    }

    public function fail(string $message): static
    {
        $this->status = PhotoStatus::ERROR;
        $this->errorMessage = mb_substr($message, 0, 4000);

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    public function setDimensions(?int $width, ?int $height): static
    {
        $this->width = $width;
        $this->height = $height;

        return $this;
    }

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function setLocation(?float $latitude, ?float $longitude): static
    {
        $this->latitude = $latitude;
        $this->longitude = $longitude;

        return $this;
    }

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function getCamera(): ?string
    {
        return $this->camera;
    }

    public function setCamera(?string $camera): static
    {
        $this->camera = $camera;

        return $this;
    }
}
