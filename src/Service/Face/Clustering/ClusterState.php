<?php

declare(strict_types=1);

namespace App\Service\Face\Clustering;

/**
 * Union-Find mit "cannot link"-Regeln (gemeinsamer Frame, verschiedene feste Personen).
 *
 * @internal
 */
final class ClusterState
{
    /** @var array<int, int> Gesicht => Repräsentant */
    private array $parent = [];

    /** @var array<int, array<string, true>> Repräsentant => Frames der Gruppe */
    private array $frames = [];

    /** @var array<int, int|null> Repräsentant => feste Person der Gruppe */
    private array $anchors = [];

    /** @var array<int, FaceNode> */
    private array $nodes = [];

    /**
     * @param list<FaceNode> $nodes
     */
    public function __construct(array $nodes)
    {
        foreach ($nodes as $node) {
            $this->nodes[$node->id] = $node;
            $this->parent[$node->id] = $node->id;
            $this->frames[$node->id] = [$node->frame => true];
            $this->anchors[$node->id] = $node->anchor;
        }
    }

    public function tryUnion(int $a, int $b): bool
    {
        $rootA = $this->find($a);
        $rootB = $this->find($b);
        if ($rootA === $rootB) {
            return true;
        }

        if (array_intersect_key($this->frames[$rootA], $this->frames[$rootB]) !== []) {
            return false;
        }

        $anchorA = $this->anchors[$rootA];
        $anchorB = $this->anchors[$rootB];
        if ($anchorA !== null && $anchorB !== null && $anchorA !== $anchorB) {
            return false;
        }

        // Kleinere Gruppe in die größere hängen
        if (\count($this->frames[$rootA]) < \count($this->frames[$rootB])) {
            [$rootA, $rootB] = [$rootB, $rootA];
        }

        $this->parent[$rootB] = $rootA;
        $this->frames[$rootA] += $this->frames[$rootB];
        $this->anchors[$rootA] ??= $this->anchors[$rootB];
        unset($this->frames[$rootB], $this->anchors[$rootB]);

        return true;
    }

    /**
     * @return list<list<FaceNode>>
     */
    public function clusters(): array
    {
        $groups = [];
        foreach ($this->nodes as $id => $node) {
            $groups[$this->find($id)][] = $node;
        }

        $clusters = array_values($groups);
        usort($clusters, static fn(array $a, array $b): int => \count($b) <=> \count($a));

        return $clusters;
    }

    private function find(int $id): int
    {
        while ($this->parent[$id] !== $id) {
            $this->parent[$id] = $this->parent[$this->parent[$id]];
            $id = $this->parent[$id];
        }

        return $id;
    }
}
