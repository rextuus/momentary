<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
readonly class StepOrder
{
    public function __construct(
        public int $stepNumber
    ) {
    }
}
