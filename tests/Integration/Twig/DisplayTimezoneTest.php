<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Zeiten werden in UTC gespeichert und in deutscher Zeit angezeigt.
 */
final class DisplayTimezoneTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function times(): iterable
    {
        yield 'Sommerzeit (UTC+2)' => ['2026-10-18 10:52:00', '18.10.2026 12:52'];
        yield 'Winterzeit (UTC+1)' => ['2026-01-10 23:30:00', '11.01.2026 00:30'];
    }

    #[DataProvider('times')]
    public function testUtcValuesAreShownInGermanTime(string $utc, string $expected): void
    {
        $twig = self::getContainer()->get(Environment::class);
        $rendered = $twig->createTemplate("{{ value|date('d.m.Y H:i') }}")->render(['value' => new \DateTimeImmutable(
            $utc,
            new \DateTimeZone('UTC'),
        )]);

        self::assertSame($expected, $rendered);
    }
}
