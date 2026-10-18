<?php

declare(strict_types=1);

namespace App\Service\Geo;

use App\Entity\Medium;
use App\Service\Video\Analyze\TagService;

/**
 * Ergänzt Medien mit GPS-Position um Ortsnamen und Tags in der Kategorie "Orte".
 */
class PlaceTagger
{
    public const string TAG_CATEGORY = 'Orte';

    public function __construct(
        private readonly ReverseGeocoder $geocoder,
        private readonly TagService $tagService,
    ) {}

    /**
     * @return bool ob ein Ortsname gefunden wurde
     */
    public function tag(Medium $medium): bool
    {
        if (!$medium->hasLocation() || !$this->geocoder->isEnabled()) {
            return false;
        }

        $place = $this->geocoder->reverse((float) $medium->getLatitude(), (float) $medium->getLongitude());
        if ($place === null) {
            return false;
        }

        $medium->setPlaceName($place->getName());
        $this->tagService->assignAiTagsToMedium($medium, [
            self::TAG_CATEGORY => array_values(array_filter([$place->locality, $place->country])),
        ]);

        return true;
    }
}
