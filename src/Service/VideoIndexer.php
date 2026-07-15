<?php

namespace App\Service;

use App\Entity\Video;

class VideoIndexer
{
    public function transform(Video $video): array
    {
        $personsGroups = [];
        foreach ($video->getVideoFaces() as $face) {
            if ($face->getPerson() && !str_starts_with($face->getPerson()->getName(), 'unknown_')) {
                file_put_contents('/tmp/indexer.log', 'Face: ' . $face->getId() . ' Scene: ' . ($face->getVideoScene() ? $face->getVideoScene()->getId() : 'null') . PHP_EOL, FILE_APPEND);
                if ($face->getVideoScene()) {
                    $name = $face->getPerson()->getName();
                    $title = $face->getVideoScene()->getTitle();
                    $personsGroups[$name][] = [
                        'start' => (int)$face->getVideoScene()->getStartSeconds(),
                        'end' => (int)$face->getVideoScene()->getEndSeconds(),
                        'title' => $title ?? 'Unbenannte Szene',
                        'thumbnailUrl' => $face->getVideoScene()->getThumbnailUrl(),
                    ];
                }
            }
        }

        $personsWithScenes = [];
        $persons = [];
        foreach ($personsGroups as $name => $intervals) {
            $persons[] = $name;
            $merged = $this->mergeIntervals($intervals);
            foreach ($merged as $interval) {
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
        foreach ($video->getScenes() as $scene) {
            foreach ($scene->getTags() as $tag) {
                $name = $tag->getName();
                $tagsGroups[$name][] = [
                    'start' => (int)$scene->getStartSeconds(),
                    'end' => (int)$scene->getEndSeconds(),
                    'title' => $scene->getTitle() ?? 'Unbenannte Szene',
                    'thumbnailUrl' => $scene->getThumbnailUrl(),
                ];
            }
        }

        $tagsWithScenes = [];
        $tags = [];
        foreach ($tagsGroups as $name => $intervals) {
            $tags[] = $name;
            $merged = $this->mergeIntervals($intervals);
            foreach ($merged as $interval) {
                $tagsWithScenes[] = [
                    'name' => $name,
                    'start' => $interval['start'],
                    'end' => $interval['end'],
                    'title' => $interval['title'],
                    'thumbnailUrl' => $interval['thumbnailUrl'],
                ];
            }
        }

        return [
            'id' => (string)$video->getId(),
            'jellyfinItemId' => $video->getJellyfinItemId(),
            'title' => $video->getTitle(),
            'persons_with_scenes' => $personsWithScenes,
            'tags_with_scenes' => $tagsWithScenes,
            'persons' => $persons,
            'tags' => $tags,
            'createdAt' => $video->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function mergeIntervals(array $intervals): array
    {
        if (empty($intervals)) return [];

        // Sort by start
        usort($intervals, fn($a, $b) => $a['start'] <=> $b['start']);

        $merged = [];
        $current = $intervals[0];

        for ($i = 1; $i < count($intervals); $i++) {
            if ($intervals[$i]['start'] <= $current['end']) {
                // Overlap or consecutive: merge
                $current['end'] = max($current['end'], $intervals[$i]['end']);
                // Aggregiere Titel, falls unterschiedlich
                if (isset($intervals[$i]['title']) && $current['title'] !== $intervals[$i]['title']) {
                    $titles = explode(', ', $current['title']);
                    if (!in_array($intervals[$i]['title'], $titles)) {
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
