<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MediumRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Gemeinsame Basis aller Medien (Video, Foto).
 *
 * Alles, was medienübergreifend gilt, hängt hier: Rechte (Eigentümer, öffentlich, Gruppen), Tags, erkannte
 * Gesichter und Ereignisse. Die Typen liegen per Class Table Inheritance in eigenen Tabellen; die ID ist über alle
 * Medien eindeutig.
 */
#[ORM\Entity(repositoryClass: MediumRepository::class)]
#[ORM\Table(name: 'medium')]
#[ORM\InheritanceType('JOINED')]
#[ORM\DiscriminatorColumn(name: 'type', type: 'string', length: 16)]
#[ORM\DiscriminatorMap(['video' => Video::class, 'photo' => Photo::class])]
#[ORM\Index(columns: ['taken_at'])]
abstract class Medium
{
    /** Herkunft der Aufnahmezeit */
    public const string DATE_EXIF = 'exif';
    public const string DATE_FILENAME = 'filename';
    public const string DATE_METADATA = 'metadata';
    public const string DATE_MANUAL = 'manual';
    /** Aufnahmedatum aus den Subcodes einer DV-Kassette (erstes Segment mit gültigem Datum, RecordingSegment) */
    public const string DATE_DV = 'dv';
    /** Kamerazeit plus gemerkte Abweichung dieser Kamera (siehe CameraClockOffset) */
    public const string DATE_CAMERA_OFFSET = 'camera_offset';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['video:list', 'video:detail', 'photo:list'])]
    protected ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['video:list', 'video:detail', 'photo:list'])]
    protected ?string $title = null;

    /** Wann das Medium importiert wurde */
    #[ORM\Column]
    #[Groups(['video:list', 'video:detail', 'photo:list'])]
    protected ?\DateTimeImmutable $createdAt = null;

    /** Wann es aufgenommen wurde (EXIF/Metadaten), sofern bekannt */
    #[ORM\Column(nullable: true)]
    #[Groups(['photo:list'])]
    protected ?\DateTimeImmutable $takenAt = null;

    /** Woher takenAt stammt (siehe DATE_*); "manual" wird von automatischen Analysen nie überschrieben */
    #[ORM\Column(length: 16, nullable: true)]
    protected ?string $dateSource = null;

    /**
     * Aufnahmezeit, wie die Kamera sie geliefert hat (EXIF, Dateiname, Metadaten), auch nach einer Korrektur.
     * Grundlage, um gemerkte Abweichungen einer Kamera anzuwenden.
     */
    #[ORM\Column(nullable: true)]
    protected ?\DateTimeImmutable $cameraTakenAt = null;

    /** Kamera bzw. Gerät (EXIF Hersteller + Modell, Video-Metadaten), z. B. "AKASO EK7000PRO" */
    #[ORM\Column(length: 128, nullable: true)]
    protected ?string $camera = null;

    /** Aufnahmeort (GPS aus EXIF bzw. Video-Metadaten) */
    #[ORM\Column(nullable: true)]
    protected ?float $latitude = null;

    #[ORM\Column(nullable: true)]
    protected ?float $longitude = null;

    /** Ortsname zum Aufnahmeort (Reverse-Geocoding), z. B. "Cala d'Or, Spanien" */
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $placeName = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    protected ?User $owner = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['video:list', 'video:detail', 'photo:list'])]
    protected bool $isPublic = false;

    /**
     * @var Collection<int, UserGroup>
     */
    #[ORM\ManyToMany(targetEntity: UserGroup::class)]
    #[ORM\JoinTable(name: 'medium_user_group')]
    protected Collection $allowedGroups;

    /**
     * Tags am ganzen Medium (bei Videos zusätzlich je Szene).
     *
     * @var Collection<int, Tag>
     */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'medium_tags')]
    #[Groups(['video:detail'])]
    protected Collection $tags;

    /**
     * @var Collection<int, VideoFace>
     */
    #[ORM\OneToMany(
        targetEntity: VideoFace::class,
        mappedBy: 'medium',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    protected Collection $faces;

    /**
     * @var Collection<int, Event>
     */
    #[ORM\ManyToMany(targetEntity: Event::class, mappedBy: 'media')]
    protected Collection $events;

    public function __construct()
    {
        $this->allowedGroups = new ArrayCollection();
        $this->tags = new ArrayCollection();
        $this->faces = new ArrayCollection();
        $this->events = new ArrayCollection();
    }

    /** "video" oder "photo" */
    abstract public function getType(): string;

    /** Vorschaubild für Listen, Ereignisse und Suche */
    abstract public function getPreviewFile(): ?File;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getTakenAt(): ?\DateTimeImmutable
    {
        return $this->takenAt;
    }

    public function setTakenAt(?\DateTimeImmutable $takenAt): static
    {
        $this->takenAt = $takenAt;

        return $this;
    }

    public function getDateSource(): ?string
    {
        return $this->dateSource;
    }

    /**
     * Aufnahmezeit mit Herkunft setzen.
     */
    public function setRecordedAt(?\DateTimeImmutable $takenAt, ?string $source): static
    {
        $this->takenAt = $takenAt;
        $this->dateSource = $takenAt === null ? null : $source;
        // Korrekturen ändern die Kamerazeit nicht
        if (!\in_array($source, [self::DATE_MANUAL, self::DATE_CAMERA_OFFSET], true)) {
            $this->cameraTakenAt = $takenAt;
        }

        return $this;
    }

    public function isDateCorrected(): bool
    {
        return \in_array($this->dateSource, [self::DATE_MANUAL, self::DATE_CAMERA_OFFSET], true);
    }

    public function getCameraTakenAt(): ?\DateTimeImmutable
    {
        return $this->cameraTakenAt;
    }

    public function getCamera(): ?string
    {
        return $this->camera;
    }

    public function setCamera(?string $camera): static
    {
        $camera = $camera === null ? null : trim($camera);
        $this->camera = $camera === '' ? null : $camera;

        return $this;
    }

    /** Für die Anzeige: woher die Aufnahmezeit stammt */
    public function getDateSourceLabel(): string
    {
        return match ($this->dateSource) {
            self::DATE_EXIF => 'laut Kamera (EXIF)',
            self::DATE_FILENAME => 'laut Dateiname',
            self::DATE_METADATA => 'laut Video-Metadaten',
            self::DATE_DV => 'laut DV-Kassette',
            self::DATE_MANUAL => 'von Hand korrigiert',
            self::DATE_CAMERA_OFFSET => 'Kamera-Uhr korrigiert',
            default => $this->takenAt === null ? 'unbekannt, Importzeitpunkt' : 'Herkunft unbekannt',
        };
    }

    /** Aufnahmedatum, sonst Importdatum: für Sortierung und Ereignisse */
    public function getDate(): ?\DateTimeImmutable
    {
        return $this->takenAt ?? $this->createdAt;
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
        if ($latitude !== $this->latitude || $longitude !== $this->longitude) {
            // Neuer Ort: alter Ortsname passt nicht mehr
            $this->placeName = null;
        }
        $this->latitude = $latitude;
        $this->longitude = $longitude;

        return $this;
    }

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function getPlaceName(): ?string
    {
        return $this->placeName;
    }

    public function setPlaceName(?string $placeName): static
    {
        $this->placeName = $placeName;

        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    public function setIsPublic(bool $isPublic): static
    {
        $this->isPublic = $isPublic;

        return $this;
    }

    /**
     * @return Collection<int, UserGroup>
     */
    public function getAllowedGroups(): Collection
    {
        return $this->allowedGroups;
    }

    public function addAllowedGroup(UserGroup $group): static
    {
        if (!$this->allowedGroups->contains($group)) {
            $this->allowedGroups->add($group);
        }

        return $this;
    }

    public function removeAllowedGroup(UserGroup $group): static
    {
        $this->allowedGroups->removeElement($group);

        return $this;
    }

    /**
     * @return Collection<int, Tag>
     */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(Tag $tag): static
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }

        return $this;
    }

    public function removeTag(Tag $tag): static
    {
        $this->tags->removeElement($tag);

        return $this;
    }

    /**
     * @return Collection<int, VideoFace>
     */
    public function getFaces(): Collection
    {
        return $this->faces;
    }

    public function addFace(VideoFace $face): static
    {
        if (!$this->faces->contains($face)) {
            $this->faces->add($face);
            $face->setMedium($this);
        }

        return $this;
    }

    public function removeFace(VideoFace $face): static
    {
        if ($this->faces->removeElement($face) && $face->getMedium() === $this) {
            $face->setMedium(null);
        }

        return $this;
    }

    /**
     * @return Collection<int, Event>
     */
    public function getEvents(): Collection
    {
        return $this->events;
    }

    /**
     * Ereignis-Seite pflegt die Zuordnung (owning side); hier nur die Gegenrichtung im Speicher halten.
     */
    public function addEvent(Event $event): static
    {
        if (!$this->events->contains($event)) {
            $this->events->add($event);
        }

        return $this;
    }

    public function removeEvent(Event $event): static
    {
        $this->events->removeElement($event);

        return $this;
    }
}
