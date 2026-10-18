<?php

declare(strict_types=1);

namespace App\Enum;

enum PersonStatus: string
{
    case NEW = 'new'; // Frisch erstellt, noch nicht bearbeitet
    case IDENTIFIED = 'identified'; // Erfolgreich zugewiesen
    case UNKNOWN = 'unknown'; // Markiert als "nicht identifizierbar" (Fremde)
    case WASTED = 'wasted'; // Markiert als unwichtig/Hintergrundrauschen
    case ARCHIVED = 'archived'; // Optional: Für späteres Aussortieren
    case MERGED = 'merged'; // Person wurde mit einer anderen zusammengeführt
    case TO_REASSIGN = 'to_reassign'; // Person/Gesichter müssen manuell neu zugeordnet werden

    public function label(): string
    {
        return match ($this) {
            self::NEW => 'Neu',
            self::IDENTIFIED => 'Identifiziert',
            self::UNKNOWN => 'Unbekannt',
            self::WASTED => 'Ausgeblendet',
            self::ARCHIVED => 'Archiviert',
            self::MERGED => 'Zusammengeführt',
            self::TO_REASSIGN => 'Neu zuordnen',
        };
    }

    /** Bootstrap-Farbe für Badges */
    public function color(): string
    {
        return match ($this) {
            self::IDENTIFIED => 'success',
            self::NEW => 'primary',
            self::TO_REASSIGN => 'warning',
            self::UNKNOWN => 'info',
            self::WASTED, self::ARCHIVED, self::MERGED => 'secondary',
        };
    }

    /**
     * Status, die die Personenliste ohne Filter zeigt: alles, was noch relevant ist.
     *
     * @return list<self>
     */
    public static function listedByDefault(): array
    {
        return [self::IDENTIFIED, self::NEW, self::TO_REASSIGN, self::UNKNOWN];
    }
}
