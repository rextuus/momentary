<?php

declare(strict_types=1);

namespace App\Enum;

enum VideoStatus: string
{
    case PENDING = 'pending';
    case CONVERTING = 'converting';
    case SCENE_DETECTION = 'scene_detection';
    case SPLITTING = 'splitting';
    case ANALYZING_FACES = 'analyzing_faces';
    case REFINING_EXTRACTION = 'refining_extraction';
    case REFINING_ANALYSIS = 'refining_analysis';
    case MERGING_SCENES = 'merging_scenes';
    case EXTRACTING_THUMBNAILS = 'extracting_thumbnails';
    case OPTIMIZING = 'optimizing';
    case COMPLETED = 'completed';
    case ERROR = 'error';
}
