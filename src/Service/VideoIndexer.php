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
                if ($face->getVideoScene()) {
                    $name = $face->getPerson()->getName();
                    $personsGroups[$name][] = [
                        'start' => (int)$face->getVideoScene()->getStartSeconds(),
                        'end' => (int)$face->getVideoScene()->getEndSeconds(),
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
                ];
            }
        }

        return [
            'id' => $video->getId(),
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
            } else {
                $merged[] = $current;
                $current = $intervals[$i];
            }
        }
        $merged[] = $current;

        return $merged;
    }
}
