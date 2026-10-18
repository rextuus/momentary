<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Person;
use App\Entity\VideoFace;

/**
 * Eine Kachel der Personenliste: Zahlen und Vorschaugesicht werden gesammelt geladen statt pro Person.
 */
final readonly class PersonListItem
{
    public function __construct(
        public Person $person,
        public int $faceCount,
        public int $videoCount,
        public ?VideoFace $previewFace,
    ) {}
}
