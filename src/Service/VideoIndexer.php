<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Video;
use App\Enum\TranscriptionStatus;

class VideoIndexer
{
    /**
     * @return array<string, mixed> Dokument für den Meilisearch-Index
     */
    public function transform(Video $video): array
    {
        $personsGroups = [];
        foreach ($video->getVideoFaces() as $face) {
            $person = $face->getPerson();
            $scene = $face->getVideoScene();
            $personName = (string) $person?->getName();

            // Unbekannte Personen (unknown_*) werden bewusst nicht indexiert
            if ($person && $personName !== '' && !str_starts_with($personName, 'unknown_') && $scene) {
                $personsGroups[$personName][] = [
                    'start' => (int) $scene->getStartSeconds(),
                    'end' => (int) $scene->getEndSeconds(),
                    'title' => $scene->getTitle() ?? 'Unbenannte Szene',
                    'thumbnailUrl' => $scene->getThumbnailUrl(),
                ];
            }
        }

        $personsWithScenes = [];
        $persons = [];
        foreach ($personsGroups as $name => $intervals) {
            $persons[] = $name;
            foreach ($this->mergeIntervals($intervals) as $interval) {
                $personsWithScenes[] = [
                    'name' => $name,
                    'start' => $interval['start'],
                    'end' => $interval['end'],
                    'title' => $interval['title'],
                    'thumbnailUrl' => $interval['thumbnailUrl'],
                ];
            }
        }

        $tagsGroups = [];
        $tagsByCategory = [];
        foreach ($video->getScenes() as $scene) {
            foreach ($scene->getTags() as $tag) {
                $name = $tag->getName();
                if ($name === null) {
                    continue;
                }
                $categoryName = $tag->getCategory()?->getName() ?? 'Uncategorized';

                $tagsGroups[$name][] = [
                    'start' => (int) $scene->getStartSeconds(),
                    'end' => (int) $scene->getEndSeconds(),
                    'title' => $scene->getTitle() ?? 'Unbenannte Szene',
                    'thumbnailUrl' => $scene->getThumbnailUrl(),
                    'category' => $categoryName,
                ];

                if (!isset($tagsByCategory[$categoryName])) {
                    $tagsByCategory[$categoryName] = [];
                }
                if (!in_array($name, $tagsByCategory[$categoryName], true)) {
                    $tagsByCategory[$categoryName][] = $name;
                }
            }
        }

        $tagsWithScenes = [];
        $tags = [];
        foreach ($tagsGroups as $name => $intervals) {
            $tags[] = $name;
            foreach ($this->mergeIntervals($intervals) as $interval) {
                $tagsWithScenes[] = [
                    'name' => $name,
                    'category' => $interval['category'] ?? 'Uncategorized',
                    'start' => $interval['start'],
                    'end' => $interval['end'],
                    'title' => $interval['title'],
                    'thumbnailUrl' => $interval['thumbnailUrl'],
                ];
            }
        }

        // Gesprochenes mit Zeitstempel: ein Treffer kann direkt an die Stelle im Video springen
        $transcript = [];
        if ($video->getTranscript()?->getStatus() === TranscriptionStatus::DONE) {
            foreach ($video->getTranscript()->getSegments() as $segment) {
                $transcript[] = [
                    'start' => (int) floor($segment->getStartSeconds()),
                    'end' => (int) ceil($segment->getEndSeconds()),
                    'text' => $segment->getText(),
                ];
            }
        }

        // Tags am ganzen Video (z. B. Orte aus GPS) zählen für Suche und Filter mit
        foreach ($video->getTags() as $tag) {
            if ($tag->getName() !== null && !\in_array($tag->getName(), $tags, true)) {
                $tags[] = $tag->getName();
            }
        }

        return [
            'id' => (string) $video->getId(),
            'jellyfinItemId' => $video->getJellyfinItemId(),
            'title' => $video->getTitle(),
            'persons_with_scenes' => $personsWithScenes,
            'tags_with_scenes' => $tagsWithScenes,
            'tags_by_category' => $tagsByCategory,
            'persons' => $persons,
            'tags' => $tags,
            'createdAt' => $video->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'transcript' => $transcript,
            'place' => $video->getPlaceName(),
        ];
    }

    /**
     * @param list<array{start: int, end: int, title: string, thumbnailUrl: string|null, category?: string}> $intervals
     *
     * @return list<array{start: int, end: int, title: string, thumbnailUrl: string|null, category?: string}>
     */
    private function mergeIntervals(array $intervals): array
    {
        if (empty($intervals)) {
            return [];
        }

        usort($intervals, static fn($a, $b) => $a['start'] <=> $b['start']);

        $merged = [];
        $current = $intervals[0];

        for ($i = 1; $i < count($intervals); $i++) {
            if ($intervals[$i]['start'] <= $current['end']) {
                // Überlappend oder direkt anschließend: zusammenführen
                $current['end'] = max($current['end'], $intervals[$i]['end']);
                if ($current['title'] !== $intervals[$i]['title']) {
                    $titles = explode(', ', $current['title']);
                    if (!in_array($intervals[$i]['title'], $titles, true)) {
                        $current['title'] = $current['title'] . ', ' . $intervals[$i]['title'];
                    }
                }
            } else {
                $merged[] = $current;
                $current = $intervals[$i];
            }
        }
        $merged[] = $current;

        return $merged;
    }
}
