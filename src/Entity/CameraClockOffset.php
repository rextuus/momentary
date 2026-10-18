<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Gemerkte Abweichung einer Kamera-Uhr: Medien dieser Kameras, deren Kamerazeit im Zeitfenster liegt, werden um
 * denselben Abstand verschoben, auch wenn sie erst später importiert werden.
 *
 * Gilt nur in einem Fenster um die korrigierten Aufnahmen, weil die Uhr irgendwann wieder gestellt wird.
 */
#[ORM\Entity]
class CameraClockOffset
{
    /** Spielraum vor und nach den korrigierten Aufnahmen */
    public const string MARGIN = '3 days';

    /** Steht in $cameras für Medien ohne Kamerakennung (viele Action-Cams schreiben keine in ihre Videos) */
    public const string UNKNOWN_CAMERA = '';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $cameras;

    /** Zeitfenster in Kamerazeit (UTC) */
    #[ORM\Column]
    private \DateTimeImmutable $cameraFrom;

    #[ORM\Column]
    private \DateTimeImmutable $cameraTo;

    /** richtige Zeit minus Kamerazeit */
    #[ORM\Column]
    private int $offsetSeconds;

    /** Woher die Korrektur stammt, z. B. der Titel des Ereignisses */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $label;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<string|null> $cameras
     */
    public function __construct(
        array $cameras,
        \DateTimeImmutable $cameraFrom,
        \DateTimeImmutable $cameraTo,
        int $offsetSeconds,
        ?string $label = null,
    ) {
        $this->cameras = array_values(array_unique(array_map(
            static fn(?string $camera): string => $camera ?? self::UNKNOWN_CAMERA,
            $cameras,
        )));
        sort($this->cameras);
        $utc = new \DateTimeZone('UTC');
        $this->cameraFrom = $cameraFrom->setTimezone($utc);
        $this->cameraTo = $cameraTo->setTimezone($utc);
        $this->offsetSeconds = $offsetSeconds;
        $this->label = $label;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return list<string>
     */
    public function getCameras(): array
    {
        return $this->cameras;
    }

    public function getCameraFrom(): \DateTimeImmutable
    {
        return $this->cameraFrom;
    }

    public function getCameraTo(): \DateTimeImmutable
    {
        return $this->cameraTo;
    }

    public function getOffsetSeconds(): int
    {
        return $this->offsetSeconds;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function matches(Medium $medium): bool
    {
        $time = $medium->getCameraTakenAt();

        return (
            $time !== null
            && \in_array($medium->getCamera() ?? self::UNKNOWN_CAMERA, $this->cameras, true)
            && $time >= $this->cameraFrom->modify('-' . self::MARGIN)
            && $time <= $this->cameraTo->modify('+' . self::MARGIN)
        );
    }

    /**
     * Für die Anzeige, z. B. "+433 Tage 2:05 Std."
     */
    public function getOffsetLabel(): string
    {
        $seconds = abs($this->offsetSeconds);
        $days = intdiv($seconds, 86_400);
        $rest = $seconds % 86_400;
        $time = sprintf('%d:%02d Std.', intdiv($rest, 3600), intdiv($rest % 3600, 60));

        return (
            ($this->offsetSeconds < 0 ? '−' : '+')
            . ($days > 0 ? sprintf('%d Tag%s ', $days, $days === 1 ? '' : 'e') : '')
            . $time
        );
    }
}
