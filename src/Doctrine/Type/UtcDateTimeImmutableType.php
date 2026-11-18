<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\Exception\InvalidFormat;

/**
 * datetime_immutable, immer in UTC: Die App läuft mit Europe/Berlin (date.timezone), gespeichert wird aber UTC.
 *
 * Beim Schreiben wird jeder Zeitpunkt nach UTC umgerechnet, beim Lesen als UTC verstanden. Ohne das las Doctrine die
 * Spalten in der PHP-Standardzeitzone, und gespeicherte UTC-Zeiten galten als Berliner Zeit (1–2 Stunden daneben).
 * Angezeigt wird in Europe/Berlin (Twig, config/packages/twig.yaml). Ersetzt den eingebauten Typ
 * (config/packages/doctrine.yaml), gilt also für alle Spalten.
 */
final class UtcDateTimeImmutableType extends DateTimeImmutableType
{
    private static ?\DateTimeZone $utc = null;

    public static function utc(): \DateTimeZone
    {
        return self::$utc ??= new \DateTimeZone('UTC');
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            $value = \DateTimeImmutable::createFromInterface($value)->setTimezone(self::utc());
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    /**
     * @throws ConversionException
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if ($value === null || $value instanceof \DateTimeImmutable) {
            return $value;
        }
        if (!\is_string($value)) {
            throw InvalidFormat::new(get_debug_type($value), self::class, $platform->getDateTimeFormatString());
        }

        $date = \DateTimeImmutable::createFromFormat($platform->getDateTimeFormatString(), $value, self::utc());
        if ($date === false) {
            // z. B. mit Sekundenbruchteilen
            $date = date_create_immutable($value, self::utc());
        }
        if ($date === false) {
            throw InvalidFormat::new($value, self::class, $platform->getDateTimeFormatString());
        }

        return $date;
    }
}
