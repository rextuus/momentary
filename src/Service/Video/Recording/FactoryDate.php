<?php

declare(strict_types=1);

namespace App\Service\Video\Recording;

/**
 * Werkseinstellung der Kamera-Uhr nach leerem Pufferakku: 1. Januar in der ersten Stunde (die Uhr läuft nach dem
 * Zurücksetzen weiter, daher nicht nur genau 00:00:00). Gilt für Fotos wie für DV-Kassetten.
 */
final class FactoryDate
{
    public static function matches(\DateTimeImmutable $cameraTime): bool
    {
        $local = $cameraTime->setTimezone(new \DateTimeZone(DvSubcodeReader::CAMERA_TIMEZONE));

        return $local->format('m-d H') === '01-01 00';
    }
}
