<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Face;

use App\Service\Face\Clustering\FaceClusterer;
use App\Service\Face\Clustering\FaceNode;
use PHPUnit\Framework\TestCase;

final class FaceClustererTest extends TestCase
{
    private const array CENTER = ['Left' => 0.4, 'Top' => 0.3, 'Width' => 0.1, 'Height' => 0.15];
    private const array LEFT = ['Left' => 0.05, 'Top' => 0.3, 'Width' => 0.1, 'Height' => 0.15];

    public function testSamePersonAcrossFramesIsGrouped(): void
    {
        $clusters = $this->cluster([
            self::face(1, frame: 'f1', candidates: []),
            self::face(2, frame: 'f2', candidates: ['face-1' => 86.0]),
            self::face(3, frame: 'f3', candidates: ['face-2' => 83.0]),
        ]);

        self::assertSame([[1, 2, 3]], $clusters);
    }

    public function testFacesBelowThresholdStaySeparate(): void
    {
        $clusters = $this->cluster([
            self::face(1, frame: 'f1'),
            self::face(2, frame: 'f2', candidates: ['face-1' => 72.0]),
        ]);

        self::assertSame([[1], [2]], $clusters);
    }

    public function testTwoFacesOfTheSameFrameAreNeverMerged(): void
    {
        $clusters = $this->cluster([
            self::face(1, frame: 'f1'),
            self::face(2, frame: 'f1', candidates: ['face-1' => 99.0]),
        ]);

        self::assertSame([[1], [2]], $clusters);
    }

    /**
     * Hochzeit: zwei Gäste in zwei Frames, Rekognition hält wegen schlechter Qualität jeden für ähnlich zu beiden.
     * Die stärkeren Treffer gewinnen, die schwächeren scheitern daran, dass die Gruppen sonst einen Frame teilen würden.
     */
    public function testCrowdedFramesDoNotCollapseIntoOnePerson(): void
    {
        $clusters = $this->cluster([
            self::face(1, frame: 'f1'),
            self::face(2, frame: 'f1'),
            self::face(3, frame: 'f2', candidates: ['face-1' => 85.0, 'face-2' => 82.0]),
            self::face(4, frame: 'f2', candidates: ['face-2' => 84.0, 'face-1' => 81.0]),
        ]);

        self::assertSame([[1, 3], [2, 4]], $clusters);
    }

    public function testChainOfWeakLinksCannotMergeEveryone(): void
    {
        // Fünf Gäste in einem Gruppenfoto; spätere Einzelaufnahmen ähneln jeweils zwei von ihnen
        $nodes = [];
        foreach (range(1, 5) as $id) {
            $nodes[] = self::face($id, frame: 'group');
        }
        foreach (range(1, 5) as $i) {
            $nodes[] = self::face(10 + $i, frame: 'single-' . $i, candidates: [
                'face-' . $i => 88.0,
                'face-' . (($i % 5) + 1) => 81.0,
            ]);
        }

        $clusters = $this->cluster($nodes);

        self::assertCount(5, $clusters);
        foreach ($clusters as $cluster) {
            self::assertCount(2, $cluster);
        }
    }

    public function testTrackingLinksFacesAtTheSamePositionWithinAScene(): void
    {
        $nodes = [
            self::face(1, frame: 'f1', scene: 7, timestamp: 10, box: self::CENTER),
            self::face(2, frame: 'f2', scene: 7, timestamp: 15, box: self::CENTER),
        ];

        self::assertSame([[1, 2]], $this->cluster($nodes));
        self::assertSame([[1], [2]], $this->cluster($nodes, tracking: false));
    }

    public function testTrackingRequiresSamePositionSameSceneAndShortGap(): void
    {
        self::assertSame(
            [[1], [2]],
            $this->cluster([
                self::face(1, frame: 'f1', scene: 7, timestamp: 10, box: self::CENTER),
                self::face(2, frame: 'f2', scene: 7, timestamp: 15, box: self::LEFT),
            ]),
            'andere Bildposition',
        );

        self::assertSame(
            [[1], [2]],
            $this->cluster([
                self::face(1, frame: 'f1', scene: 7, timestamp: 10, box: self::CENTER),
                self::face(2, frame: 'f2', scene: 8, timestamp: 15, box: self::CENTER),
            ]),
            'andere Szene',
        );

        self::assertSame(
            [[1], [2]],
            $this->cluster([
                self::face(1, frame: 'f1', scene: 7, timestamp: 10, box: self::CENTER),
                self::face(2, frame: 'f2', scene: 7, timestamp: 30, box: self::CENTER),
            ]),
            'zu großer Abstand',
        );
    }

    public function testFacesOfTwoDifferentConfirmedPersonsAreNeverMerged(): void
    {
        $clusters = $this->cluster([
            self::face(1, frame: 'f1', anchor: 100),
            self::face(2, frame: 'f2', candidates: ['face-1' => 95.0], anchor: 200),
        ]);

        self::assertSame([[1], [2]], $clusters);
    }

    public function testConfirmedPersonPullsInUnconfirmedFaces(): void
    {
        $clusters = new FaceClusterer()->cluster([
            self::face(1, frame: 'f1', anchor: 100),
            self::face(2, frame: 'f2', candidates: ['face-1' => 90.0]),
        ], linkThreshold: 80.0);

        self::assertCount(1, $clusters);
        self::assertSame([100, null], array_map(static fn(FaceNode $n): ?int => $n->anchor, $clusters[0]));
    }

    public function testIntersectionOverUnion(): void
    {
        self::assertEqualsWithDelta(1.0, FaceClusterer::iou(self::CENTER, self::CENTER), 0.0001);
        self::assertSame(0.0, FaceClusterer::iou(self::CENTER, self::LEFT));
        self::assertEqualsWithDelta(
            1 / 3,
            FaceClusterer::iou(['left' => 0.0, 'top' => 0.0, 'width' => 0.2, 'height' => 0.1], [
                'Left' => 0.1,
                'Top' => 0.0,
                'Width' => 0.2,
                'Height' => 0.1,
            ]),
            0.0001,
        );
    }

    /**
     * @param list<FaceNode> $nodes
     *
     * @return list<list<int>> IDs je Gruppe, sortiert
     */
    private function cluster(array $nodes, bool $tracking = true): array
    {
        $clusters = new FaceClusterer()->cluster(
            $nodes,
            linkThreshold: 80.0,
            trackingEnabled: $tracking,
            trackingMinIou: 0.3,
            trackingMaxGapSeconds: 6,
        );

        $ids = array_map(static function (array $cluster): array {
            $ids = array_map(static fn(FaceNode $node): int => $node->id, $cluster);
            sort($ids);

            return $ids;
        }, $clusters);
        usort($ids, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        return $ids;
    }

    /**
     * @param array<string, float> $candidates
     * @param array<string, float> $box
     */
    private static function face(
        int $id,
        string $frame,
        array $candidates = [],
        ?int $scene = null,
        int $timestamp = 0,
        array $box = [],
        ?int $anchor = null,
    ): FaceNode {
        return new FaceNode($id, $frame, 'face-' . $id, $candidates, $scene, $timestamp, $box, $anchor);
    }
}
