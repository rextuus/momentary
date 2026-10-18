<?php

declare(strict_types=1);

namespace App\Twig\Extension;

use App\Entity\Medium;
use App\Entity\Photo;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Helfer für Templates, die mit Videos und Fotos gleichermaßen umgehen.
 */
final class MediaExtension extends AbstractExtension
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('medium_path', $this->mediumPath(...)),
            new TwigFunction('medium_icon', $this->mediumIcon(...)),
        ];
    }

    /**
     * Detailseite eines Mediums; bei Videos optional mit Sprung zur Sekunde.
     */
    public function mediumPath(?Medium $medium, ?int $seconds = null): string
    {
        if ($medium === null || $medium->getId() === null) {
            return '#';
        }

        if ($medium instanceof Photo) {
            return $this->urlGenerator->generate('app_photo_show', ['id' => $medium->getId()]);
        }

        $url = $this->urlGenerator->generate('app_video_show', ['id' => $medium->getId()]);

        return $seconds !== null ? $url . '?t=' . $seconds : $url;
    }

    public function mediumIcon(?Medium $medium): string
    {
        return $medium instanceof Photo ? 'fa-image' : 'fa-video';
    }
}
