<?php

declare(strict_types=1);

namespace App\Service\Video;

use App\Entity\Video;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Kurzlebige, signierte Stream-URLs für Videos: funktionieren ohne Anmeldung und ohne Jellyfin-API-Key, deshalb auch
 * zum Casten auf den Fernseher (Hub, Android-App).
 *
 * Signiert wird nur Pfad und Query (mit einem festen Platzhalter-Host), damit die URL hinter einem Reverse Proxy oder
 * unter einer anderen Adresse gültig bleibt. Die öffentliche Adresse kommt aus APP_PUBLIC_URL, sonst aus der Anfrage.
 */
class VideoStreamUrls
{
    /** So lange darf ein Film laufen (Casten holt die Datei stückweise über die ganze Spielzeit) */
    public const int TTL_SECONDS = 6 * 3600;

    private const string CANONICAL_HOST = 'https://stream.momentary.invalid';

    public function __construct(
        private readonly UriSigner $signer,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire('%env(default::APP_PUBLIC_URL)%')]
        private readonly ?string $publicUrl = null,
    ) {}

    /**
     * @return array{url: string, expiresAt: \DateTimeImmutable}
     */
    public function create(
        Video $video,
        Request $request,
        int $ttl = self::TTL_SECONDS,
        string $quality = VideoFiles::QUALITY_WEB,
    ): array {
        $expiresAt = new \DateTimeImmutable(sprintf('+%d seconds', $ttl));
        // Die Qualität ist Teil der signierten URL: sie lässt sich nicht nachträglich umstellen
        $path = $this->urlGenerator->generate('app_video_stream', ['id' => $video->getId(), 'quality' => $quality]);
        $signed = substr($this->signer->sign(self::CANONICAL_HOST . $path, $expiresAt), \strlen(self::CANONICAL_HOST));

        $base = trim((string) $this->publicUrl) !== ''
            ? rtrim((string) $this->publicUrl, '/')
            : $request->getSchemeAndHttpHost() . $request->getBasePath();

        return ['url' => $base . $signed, 'expiresAt' => $expiresAt];
    }

    /**
     * Gültige Signatur und nicht abgelaufen?
     */
    public function isValid(Request $request): bool
    {
        $query = $request->getQueryString();

        return $this->signer->check(
            self::CANONICAL_HOST . $request->getPathInfo() . ($query !== null ? '?' . $query : ''),
        );
    }
}
