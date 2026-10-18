<?php

declare(strict_types=1);

namespace App\Service\Geo;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Ortsnamen zu GPS-Koordinaten über OpenStreetMap Nominatim.
 *
 * Nutzungsregeln von Nominatim: höchstens eine Anfrage pro Sekunde, aussagekräftiger User-Agent, Ergebnisse cachen.
 * Koordinaten werden auf drei Nachkommastellen (~100 m) gerundet, bevor sie das Haus verlassen; das genügt für einen
 * Ortsnamen und verbessert die Trefferquote des Caches. Abschaltbar über GEOCODING_ENABLED=0.
 */
class ReverseGeocoder
{
    private const float MIN_SECONDS_BETWEEN_REQUESTS = 1.1;

    private static float $lastRequest = 0.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(bool:GEOCODING_ENABLED)%')]
        private readonly bool $enabled,
        #[Autowire('%env(NOMINATIM_URL)%')]
        private readonly string $baseUrl,
    ) {}

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function reverse(float $latitude, float $longitude): ?Place
    {
        if (!$this->enabled) {
            return null;
        }

        $lat = round($latitude, 3);
        $lon = round($longitude, 3);
        $key = sprintf(
            'geo_%s_%s',
            str_replace(['.', '-'], ['_', 'm'], (string) $lat),
            str_replace(['.', '-'], ['_', 'm'], (string) $lon),
        );

        try {
            $cached = $this->cache->get($key, function (ItemInterface $item) use ($lat, $lon): array {
                $item->expiresAfter(365 * 24 * 3600);

                return $this->fetch($lat, $lon);
            });
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('Reverse-Geocoding %s,%s: %s', $lat, $lon, $e->getMessage()));

            return null;
        }

        if ($cached['locality'] === null && $cached['country'] === null) {
            return null;
        }

        return new Place($cached['locality'], $cached['country']);
    }

    /**
     * @return array{locality: ?string, country: ?string}
     */
    private function fetch(float $lat, float $lon): array
    {
        $wait = self::MIN_SECONDS_BETWEEN_REQUESTS - (microtime(true) - self::$lastRequest);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        self::$lastRequest = microtime(true);

        $data = $this->httpClient->request('GET', rtrim($this->baseUrl, '/') . '/reverse', [
            'query' => [
                'format' => 'jsonv2',
                'lat' => $lat,
                'lon' => $lon,
                'zoom' => 14,
                'accept-language' => 'de',
            ],
            'headers' => ['User-Agent' => 'Momentary/1.0 (privates Familien-Medienarchiv, selbst gehostet)'],
            'timeout' => 10,
        ])->toArray();

        $address = (array) ($data['address'] ?? []);
        $locality = null;
        foreach (['city', 'town', 'village', 'hamlet', 'municipality', 'suburb', 'county'] as $field) {
            if (\is_string($address[$field] ?? null) && $address[$field] !== '') {
                $locality = $address[$field];
                break;
            }
        }

        return [
            'locality' => $locality,
            'country' => \is_string($address['country'] ?? null) ? $address['country'] : null,
        ];
    }
}
