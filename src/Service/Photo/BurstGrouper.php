<?php

declare(strict_types=1);

namespace App\Service\Photo;

use App\Entity\Photo;

/**
 * Bildet Serien aus einer nach Eigentümer und Aufnahmezeit sortierten Fotoliste.
 *
 * Eine Serie bilden Fotos desselben Eigentümers, die höchstens MAX_GAP_SECONDS nach dem vorigen aufgenommen wurden
 * und ihm laut Fingerabdruck ähneln. Bestes Foto: vom Benutzer gewählt, sonst das mit den meisten Gesichtern, dann
 * das schärfste.
 */
final class BurstGrouper
{
    public const int MAX_GAP_SECONDS = 10;
    public const float MIN_SIMILARITY = 0.6;

    /**
     * @param list<Photo> $photos nach Eigentümer und Aufnahmezeit sortiert
     *
     * @return int Anzahl Serien
     */
    public static function apply(array $photos): int
    {
        $groups = [];
        $group = [];
        foreach ($photos as $photo) {
            // Ausgeschlossene Fotos und solche ohne Fingerabdruck unterbrechen keine Serie
            if (!self::groupable($photo)) {
                $photo->setBurstCover(null);
                continue;
            }
            $previous = $group[\count($group) - 1] ?? null;
            if ($previous === null || !self::continues($previous, $photo)) {
                $groups[] = $group;
                $group = [];
            }
            $group[] = $photo;
        }
        $groups[] = $group;

        $count = 0;
        foreach ($groups as $members) {
            if (\count($members) < 2) {
                foreach ($members as $photo) {
                    $photo->setBurstCover(null);
                }
                continue;
            }
            $cover = self::best($members);
            foreach ($members as $photo) {
                $photo->setBurstCover($photo === $cover ? null : $cover);
            }
            $count++;
        }

        return $count;
    }

    private static function groupable(Photo $photo): bool
    {
        return !$photo->isBurstExcluded() && $photo->getFingerprint() !== null && $photo->getTakenAt() !== null;
    }

    private static function continues(Photo $previous, Photo $photo): bool
    {
        if ($previous->getOwner() !== $photo->getOwner()) {
            return false;
        }
        $gap = (int) $photo->getTakenAt()?->getTimestamp() - (int) $previous->getTakenAt()?->getTimestamp();

        return (
            $gap <= self::MAX_GAP_SECONDS
            && PhotoFingerprint::similarity($previous->getFingerprint(), $photo->getFingerprint())
            >= self::MIN_SIMILARITY
        );
    }

    /**
     * @param non-empty-list<Photo> $members
     */
    private static function best(array $members): Photo
    {
        foreach ($members as $photo) {
            if ($photo->isBurstPinned()) {
                return $photo;
            }
        }
        usort(
            $members,
            static fn(Photo $a, Photo $b): int => (
                [
                    $b->getFaces()->count(),
                    $b->getSharpness() ?? 0.0,
                    $a->getId(),
                ] <=> [
                    $a->getFaces()->count(),
                    $a->getSharpness() ?? 0.0,
                    $b->getId(),
                ]
            ),
        );

        return $members[0];
    }
}
