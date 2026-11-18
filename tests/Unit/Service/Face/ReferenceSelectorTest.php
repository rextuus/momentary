<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Face;

use App\Service\Face\Embedding\ReferenceCandidate;
use App\Service\Face\Embedding\ReferenceSelector;
use PHPUnit\Framework\TestCase;

final class ReferenceSelectorTest extends TestCase
{
    public function testOnlySureAndLargeFacesBecomeReferencesVerifiedFirst(): void
    {
        $selected = ReferenceSelector::select(
            [
                new ReferenceCandidate(1, personId: 7, verified: false, detectionScore: 95.0, sizePx: 120),
                new ReferenceCandidate(2, personId: 7, verified: true, detectionScore: 75.0, sizePx: 80),
                new ReferenceCandidate(3, personId: 7, verified: true, detectionScore: 99.0, sizePx: 30), // zu klein
                new ReferenceCandidate(4, personId: 7, verified: false, detectionScore: 50.0, sizePx: 200), // unsicher
                new ReferenceCandidate(5, personId: 7, verified: false, detectionScore: 90.0, sizePx: 90),
            ],
            perPerson: 2,
            minScore: 70.0,
            minSizePx: 60,
        );

        self::assertSame([2, 1], $selected);
    }

    public function testPersonWithoutGoodFacesKeepsItsBestFew(): void
    {
        $selected = ReferenceSelector::select(
            [
                new ReferenceCandidate(1, personId: 3, verified: false, detectionScore: 60.0, sizePx: 40),
                new ReferenceCandidate(2, personId: 3, verified: false, detectionScore: 65.0, sizePx: 30),
                new ReferenceCandidate(3, personId: 3, verified: false, detectionScore: 55.0, sizePx: 50),
                new ReferenceCandidate(4, personId: 3, verified: false, detectionScore: 50.0, sizePx: 50),
                new ReferenceCandidate(5, personId: 9, verified: false, detectionScore: 90.0, sizePx: 100),
            ],
            perPerson: 20,
            minScore: 70.0,
            minSizePx: 60,
        );

        self::assertSame([5, 2, 1, 3], $selected, 'Person 3 nur mit ihren drei besten, Person 9 normal');
    }

    public function testMissingScoreOrSizeNeverQualifies(): void
    {
        $selected = ReferenceSelector::select(
            [
                new ReferenceCandidate(1, personId: 1, verified: true, detectionScore: null, sizePx: null),
                new ReferenceCandidate(2, personId: 1, verified: false, detectionScore: 80.0, sizePx: 80),
            ],
            perPerson: 5,
            minScore: 70.0,
            minSizePx: 60,
        );

        self::assertSame([2], $selected);
    }

    public function testReferencesAreSpreadOverYearsNearYearsFirst(): void
    {
        $face = static fn(int $id, ?int $year, float $score = 90.0): ReferenceCandidate => new ReferenceCandidate(
            $id,
            personId: 1,
            verified: false,
            detectionScore: $score,
            sizePx: 100,
            year: $year,
        );
        $faces = [
            $face(1, 1990, 99.0),
            $face(2, 1990, 98.0),
            $face(3, 1990, 97.0),
            $face(4, 2000, 95.0),
            $face(5, 2000, 94.0),
            $face(6, 2010, 99.0),
        ];

        // Analysiert wird ein Video von 2001: erst je das beste aus 2000, 2010, 1990 (nach Abstand), dann die zweitbesten
        self::assertSame(
            [4, 6, 1, 5],
            ReferenceSelector::select($faces, perPerson: 4, minScore: 70.0, minSizePx: 60, targetYear: 2001),
        );
        // Ohne Aufnahmejahr des Mediums: keine Vorliebe, trotzdem über alle Jahre verteilt
        self::assertSame([1, 4, 6], ReferenceSelector::select($faces, perPerson: 3, minScore: 70.0, minSizePx: 60));
    }

    public function testFacesWithoutYearCountNeutrally(): void
    {
        $face = static fn(int $id, ?int $year): ReferenceCandidate => new ReferenceCandidate(
            $id,
            personId: 1,
            verified: false,
            detectionScore: 90.0 - $id,
            sizePx: 100,
            year: $year,
        );

        // Gruppen 1995 (nah) | ohne Jahr | 1980 (fern): die Gruppe ohne Jahr steht in der Mitte
        self::assertSame(
            [1, 2, 3, 4],
            ReferenceSelector::select(
                [$face(1, 1995), $face(2, null), $face(3, 1980), $face(4, 1995)],
                perPerson: 4,
                minScore: 70.0,
                minSizePx: 60,
                targetYear: 1996,
            ),
        );
    }
}
