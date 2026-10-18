<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PhotoStatus;
use App\Repository\PhotoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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

    /** Bild-Fingerabdruck für Serienfotos (siehe PhotoFingerprint) */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $fingerprint = null;

    /** Schärfe (Varianz des Laplace-Filters), für die Wahl des besten Fotos einer Serie */
    #[ORM\Column(nullable: true)]
    private ?float $sharpness = null;

    /** Serienfoto: das beste Foto der Serie, das stellvertretend gezeigt wird; null beim besten Foto selbst */
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'burstMembers')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Photo $burstCover = null;

    /**
     * @var Collection<int, Photo>
     */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'burstCover')]
    #[ORM\OrderBy(['takenAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $burstMembers;

    /** Vom Benutzer als bestes Foto seiner Serie gewählt */
    #[ORM\Column(options: ['default' => false])]
    private bool $burstPinned = false;

    /** Vom Benutzer aus der Serie gelöst: wird nie wieder einer Serie zugeordnet */
    #[ORM\Column(options: ['default' => false])]
    private bool $burstExcluded = false;

    public function __construct()
    {
        parent::__construct();
        $this->burstMembers = new ArrayCollection();
    }

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

    public function getFingerprint(): ?string
    {
        return $this->fingerprint;
    }

    public function getSharpness(): ?float
    {
        return $this->sharpness;
    }

    public function setImageMetrics(?string $fingerprint, ?float $sharpness): static
    {
        $this->fingerprint = $fingerprint;
        $this->sharpness = $sharpness;

        return $this;
    }

    public function getBurstCover(): ?Photo
    {
        return $this->burstCover;
    }

    public function setBurstCover(?Photo $burstCover): static
    {
        $this->burstCover?->burstMembers->removeElement($this);
        $this->burstCover = $burstCover;
        if ($burstCover !== null && !$burstCover->burstMembers->contains($this)) {
            $burstCover->burstMembers->add($this);
        }

        return $this;
    }

    /**
     * Die übrigen Fotos der Serie, wenn dies das beste ist.
     *
     * @return Collection<int, Photo>
     */
    public function getBurstMembers(): Collection
    {
        return $this->burstMembers;
    }

    /**
     * Alle Fotos der Serie (inklusive diesem) nach Aufnahmezeit, oder nur dieses Foto.
     *
     * @return list<Photo>
     */
    public function getBurst(): array
    {
        $cover = $this->burstCover ?? $this;
        $photos = [$cover, ...$cover->burstMembers->toArray()];
        usort(
            $photos,
            static fn(Photo $a, Photo $b): int => [$a->getDate(), $a->getId()] <=> [$b->getDate(), $b->getId()],
        );

        return $photos;
    }

    public function isBurstPinned(): bool
    {
        return $this->burstPinned;
    }

    public function setBurstPinned(bool $burstPinned): static
    {
        $this->burstPinned = $burstPinned;

        return $this;
    }

    public function isBurstExcluded(): bool
    {
        return $this->burstExcluded;
    }

    public function setBurstExcluded(bool $burstExcluded): static
    {
        $this->burstExcluded = $burstExcluded;

        return $this;
    }
}
