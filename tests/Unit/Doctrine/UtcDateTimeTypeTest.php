<?php

declare(strict_types=1);

namespace App\Tests\Unit\Doctrine;

use App\Doctrine\Type\UtcDateTimeImmutableType;
use App\Doctrine\Type\UtcDateTimeType;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use PHPUnit\Framework\TestCase;

/**
 * Gespeichert wird UTC, unabhängig von der Zeitzone, in der ein Zeitpunkt erzeugt wurde (App: Europe/Berlin).
 */
final class UtcDateTimeTypeTest extends TestCase
{
    public function testBerlinTimeIsStoredAsUtcAcrossDaylightSaving(): void
    {
        $type = new UtcDateTimeImmutableType();
        $platform = new MySQLPlatform();
        $berlin = new \DateTimeZone('Europe/Berlin');

        self::assertSame(
            '2025-03-19 11:15:37',
            $type->convertToDatabaseValue(new \DateTimeImmutable('2025-03-19 12:15:37', $berlin), $platform),
            'Winterzeit: UTC+1',
        );
        self::assertSame(
            '2026-05-25 10:00:00',
            $type->convertToDatabaseValue(new \DateTimeImmutable('2026-05-25 12:00:00', $berlin), $platform),
            'Sommerzeit: UTC+2',
        );
    }

    public function testStoredValuesAreReadAsUtc(): void
    {
        $date = new UtcDateTimeImmutableType()->convertToPHPValue('2026-05-25 10:00:00', new MySQLPlatform());

        self::assertSame('UTC', $date->getTimezone()->getName());
        self::assertSame(
            '2026-05-25 12:00',
            $date->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d H:i'),
            'angezeigt in Europe/Berlin',
        );
        self::assertSame(1779703200, $date->getTimestamp());
    }

    public function testRoundTripKeepsTheInstant(): void
    {
        $type = new UtcDateTimeImmutableType();
        $platform = new MySQLPlatform();
        $original = new \DateTimeImmutable('2025-10-26 02:30:00', new \DateTimeZone('America/New_York'));

        $restored = $type->convertToPHPValue($type->convertToDatabaseValue($original, $platform), $platform);

        self::assertSame($original->getTimestamp(), $restored->getTimestamp());
    }

    public function testMutableTypeAlsoStoresUtc(): void
    {
        $type = new UtcDateTimeType();
        $platform = new MySQLPlatform();
        $value = new \DateTime('2026-01-10 08:00:00', new \DateTimeZone('Europe/Berlin'));

        self::assertSame('2026-01-10 07:00:00', $type->convertToDatabaseValue($value, $platform));
        self::assertSame('2026-01-10 08:00:00', $value->format('Y-m-d H:i:s'), 'Original bleibt unverändert');
        self::assertSame('UTC', $type->convertToPHPValue('2026-01-10 07:00:00', $platform)->getTimezone()->getName());
    }
}
