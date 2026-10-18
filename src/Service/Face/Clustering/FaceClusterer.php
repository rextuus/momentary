<?php

declare(strict_types=1);

namespace App\Service\Face\Clustering;

/**
 * Gruppiert die Gesichter eines Videos zu Personen.
 *
 * Verbindungen entstehen aus Rekognition-Ähnlichkeiten und (optional) aus Tracking: dasselbe Gesicht an fast
 * gleicher Bildposition in kurz aufeinanderfolgenden Frames einer Szene. Die stärksten Verbindungen werden zuerst
 * verarbeitet. Zwei Gruppen werden nur zusammengelegt, wenn
 *  - sie keinen gemeinsamen Frame haben (zwei Gesichter in einem Bild sind zwei Menschen) und
 *  - sie nicht zu zwei verschiedenen festen Personen gehören.
 * Dadurch darf die Ähnlichkeitsschwelle deutlich niedriger liegen, ohne dass alle Gäste zu einer Person verschmelzen.
 */
final class FaceClusterer
{
    /**
     * @param list<FaceNode> $nodes
     *
     * @return list<list<FaceNode>> Gruppen, die größten zuerst
     */
    public function cluster(
        array $nodes,
        float $linkThreshold,
        bool $trackingEnabled = true,
        float $trackingMinIou = 0.3,
        int $trackingMaxGapSeconds = 6,
    ): array {
        $state = new ClusterState($nodes);

        $edges = $this->similarityEdges($nodes, $linkThreshold);
        if ($trackingEnabled) {
            array_push($edges, ...$this->trackingEdges($nodes, $trackingMinIou, $trackingMaxGapSeconds));
        }
        usort($edges, static fn(array $a, array $b): int => $b[2] <=> $a[2]);

        foreach ($edges as [$a, $b]) {
            $state->tryUnion($a, $b);
        }

        return $state->clusters();
    }

    /**
     * @param list<FaceNode> $nodes
     *
     * @return list<array{int, int, float}> [Gesicht A, Gesicht B, Stärke 0..100]
     */
    private function similarityEdges(array $nodes, float $threshold): array
    {
        $byLabel = [];
        foreach ($nodes as $node) {
            if ($node->label !== null) {
                $byLabel[$node->label] = $node->id;
            }
        }

        $edges = [];
        foreach ($nodes as $node) {
            foreach ($node->candidates as $label => $similarity) {
                $other = $byLabel[$label] ?? null;
                if ($other !== null && $other !== $node->id && $similarity >= $threshold) {
                    $edges[] = [$node->id, $other, $similarity];
                }
            }
        }

        return $edges;
    }

    /**
     * Tracking-Verbindungen haben die Überlappung (0..1) mal 100 als Stärke und rangieren damit
     * hinter sicheren Rekognition-Treffern.
     *
     * @param list<FaceNode> $nodes
     *
     * @return list<array{int, int, float}>
     */
    private function trackingEdges(array $nodes, float $minIou, int $maxGapSeconds): array
    {
        $byScene = [];
        foreach ($nodes as $node) {
            if ($node->sceneId !== null && $node->boundingBox !== []) {
                $byScene[$node->sceneId][] = $node;
            }
        }

        $edges = [];
        foreach ($byScene as $sceneNodes) {
            usort($sceneNodes, static fn(FaceNode $a, FaceNode $b): int => $a->timestamp <=> $b->timestamp);
            $count = \count($sceneNodes);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $gap = $sceneNodes[$j]->timestamp - $sceneNodes[$i]->timestamp;
                    if ($gap > $maxGapSeconds) {
                        break;
                    }
                    if ($sceneNodes[$i]->frame === $sceneNodes[$j]->frame) {
                        continue;
                    }

                    $iou = self::iou($sceneNodes[$i]->boundingBox, $sceneNodes[$j]->boundingBox);
                    if ($iou >= $minIou) {
                        $edges[] = [$sceneNodes[$i]->id, $sceneNodes[$j]->id, $iou * 100];
                    }
                }
            }
        }

        return $edges;
    }

    /**
     * Intersection over Union zweier relativer Boxen. Akzeptiert Rekognition-Schlüssel (Left/Top/Width/Height)
     * und kleingeschriebene Varianten.
     *
     * @param array<string, float> $a
     * @param array<string, float> $b
     */
    public static function iou(array $a, array $b): float
    {
        [$ax, $ay, $aw, $ah] = self::box($a);
        [$bx, $by, $bw, $bh] = self::box($b);

        $overlapWidth = max(0.0, min($ax + $aw, $bx + $bw) - max($ax, $bx));
        $overlapHeight = max(0.0, min($ay + $ah, $by + $bh) - max($ay, $by));
        $intersection = $overlapWidth * $overlapHeight;
        $union = ($aw * $ah) + ($bw * $bh) - $intersection;

        return $union > 0.0 ? $intersection / $union : 0.0;
    }

    /**
     * @param array<string, float> $box
     *
     * @return array{float, float, float, float}
     */
    private static function box(array $box): array
    {
        $value = static fn(string $key): float => (float) ($box[$key] ?? $box[strtolower($key)] ?? 0.0);

        return [$value('Left'), $value('Top'), $value('Width'), $value('Height')];
    }
}
