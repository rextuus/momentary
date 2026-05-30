<?php

declare(strict_types=1);

namespace App\Enum;

enum PersonStatus: string
{
    case NEW = 'new';                 // Frisch erstellt, noch nicht bearbeitet
    case IDENTIFIED = 'identified';   // Erfolgreich zugewiesen
    case UNKNOWN = 'unknown';         // Markiert als "nicht identifizierbar" (Fremde)
    case WASTED = 'wasted';           // Markiert als unwichtig/Hintergrundrauschen
    case ARCHIVED = 'archived';       // Optional: Für späteres Aussortieren
}
