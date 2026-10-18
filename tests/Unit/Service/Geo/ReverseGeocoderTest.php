<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Geo;

use App\Entity\Photo;
use App\Service\Geo\PlaceTagger;
use App\Service\Geo\ReverseGeocoder;
use App\Service\Video\Analyze\BetterVideoAnalyzer;
use App\Service\Video\Analyze\TagService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class ReverseGeocoderTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?array{float, float}}>
     */
    public static function iso6709(): iterable
    {
        yield 'iPhone mit Höhe' => ['+39.5700+002.6500+012.345/', [39.57, 2.65]];
        yield 'ohne Höhe' => ['-33.8688+151.2093/', [-33.8688, 151.2093]];
        yield 'Nullpunkt (Kamera ohne GPS)' => ['+00.0000+000.0000/', null];
        yield 'Unsinn' => ['irgendwo', null];
        yield 'fehlt' => [null, null];
    }

    /**
     * @param array{float, float}|null $expected
     */
    #[DataProvider('iso6709')]
    public function testParsesIso6709FromVideoMetadata(?string $value, ?array $expected): void
    {
        self::assertSame($expected, BetterVideoAnalyzer::parseIso6709($value));
    }

    public function testRoundsCoordinatesCachesAndPicksLocality(): void
    {
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (
            &$requests,
        ): JsonMockResponse {
            $requests[] = $url;

            return new JsonMockResponse(['address' => ['village' => 'Cala d\'Or', 'country' => 'Spanien']]);
        });
        $geocoder = new ReverseGeocoder($client, new ArrayAdapter(), new NullLogger(), true, 'https://nominatim.test');

        $place = $geocoder->reverse(39.374123, 3.228987);
        $again = $geocoder->reverse(39.37449, 3.22901); // gleicher ~100-m-Bereich

        self::assertSame('Cala d\'Or, Spanien', $place?->getName());
        self::assertSame('Cala d\'Or, Spanien', $again?->getName());
        self::assertCount(1, $requests, 'zweite Anfrage kommt aus dem Cache');
        self::assertStringContainsString('lat=39.374', $requests[0]);
        self::assertStringContainsString('lon=3.229', $requests[0]);
        self::assertStringNotContainsString('39.374123', $requests[0], 'genaue Koordinaten verlassen das Haus nicht');
    }

    public function testDisabledGeocoderSendsNothing(): void
    {
        $client = new MockHttpClient(static fn() => throw new \LogicException('keine Anfrage erwartet'));
        $geocoder = new ReverseGeocoder($client, new ArrayAdapter(), new NullLogger(), false, 'https://nominatim.test');

        self::assertNull($geocoder->reverse(39.37, 3.22));
    }

    public function testPlaceTaggerStoresNameAndTags(): void
    {
        $client = new MockHttpClient(new JsonMockResponse(['address' => [
            'town' => 'Santanyí',
            'country' => 'Spanien',
        ]]));
        $geocoder = new ReverseGeocoder($client, new ArrayAdapter(), new NullLogger(), true, 'https://nominatim.test');
        $tagService = $this->createMock(TagService::class);
        $tagService
            ->expects(self::once())
            ->method('assignAiTagsToMedium')
            ->with(self::isInstanceOf(Photo::class), ['Orte' => ['Santanyí', 'Spanien']]);

        $photo = new Photo()->setLocation(39.35, 3.13);

        self::assertTrue(new PlaceTagger($geocoder, $tagService)->tag($photo));
        self::assertSame('Santanyí, Spanien', $photo->getPlaceName());
    }
}
