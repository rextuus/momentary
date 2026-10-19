<?php

declare(strict_types=1);

namespace App\Twig\Extension;

use App\Entity\Medium;
use App\Entity\Person;
use App\Entity\Photo;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Helfer für Templates, die mit Videos und Fotos gleichermaßen umgehen.
 */
final class MediaExtension extends AbstractExtension
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {}

    public function getFilters(): array
    {
        return [new TwigFilter('highlight', $this->highlight(...), ['is_safe' => ['html']])];
    }

    /**
     * Suchbegriffe im Text mit <mark> hervorheben (Text wird vorher maskiert).
     *
     * @param list<string> $terms
     */
    public function highlight(string $text, array $terms): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $terms = array_filter($terms, static fn(string $term): bool => $term !== '');
        if ($terms === []) {
            return $escaped;
        }

        $pattern = implode('|', array_map(static fn(string $term): string => preg_quote(
            htmlspecialchars($term, ENT_QUOTES, 'UTF-8'),
            '/',
        ), $terms));

        return preg_replace('/(' . $pattern . ')/iu', '<mark>$1</mark>', $escaped) ?? $escaped;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('medium_path', $this->mediumPath(...)),
            new TwigFunction('medium_icon', $this->mediumIcon(...)),
            new TwigFunction('person_path', $this->personPath(...)),
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

    /**
     * Link zu einer Person: Bearbeiter kommen zur Personenseite, alle anderen (Familie im Pool) zur Zeitachse mit allen
     * Medien dieser Person, die sie sehen dürfen.
     */
    public function personPath(?Person $person): string
    {
        if ($person === null || $person->getId() === null) {
            return '#';
        }

        return (
            $this->authorizationChecker->isGranted('ROLE_EDITOR')
                ? $this->urlGenerator->generate('person_detail', ['id' => $person->getId()])
                : $this->urlGenerator->generate('app_media_timeline', ['person' => $person->getId()])
        );
    }
}
