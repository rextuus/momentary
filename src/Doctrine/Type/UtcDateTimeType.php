<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\DateTimeType;
use Doctrine\DBAL\Types\Exception\InvalidFormat;

/**
 * datetime (veränderlich), immer in UTC; wie UtcDateTimeImmutableType. Die eigenen Entities nutzen
 * DateTimeImmutable, der Typ deckt Spalten aus Bundles ab.
 */
final class UtcDateTimeType extends DateTimeType
{
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            $value = \DateTime::createFromInterface($value)->setTimezone(UtcDateTimeImmutableType::utc());
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    /**
     * @throws ConversionException
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTime
    {
        if ($value === null || $value instanceof \DateTime) {
            return $value;
        }
        if (!\is_string($value)) {
            throw InvalidFormat::new(get_debug_type($value), self::class, $platform->getDateTimeFormatString());
        }

        $utc = UtcDateTimeImmutableType::utc();
        $date = \DateTime::createFromFormat($platform->getDateTimeFormatString(), $value, $utc);
        if ($date === false) {
            $date = date_create($value, $utc);
        }
        if ($date === false) {
            throw InvalidFormat::new($value, self::class, $platform->getDateTimeFormatString());
        }

        return $date;
    }
}
