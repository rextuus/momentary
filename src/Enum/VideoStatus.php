<?php

declare(strict_types=1);

namespace App\Enum;

enum VideoStatus: string
{
    case PENDING = 'pending';
    case CONVERTING = 'converting';
    case SCENE_DETECTION = 'scene_detection';
    case EXTRACTING_THUMBNAILS = 'extracting_thumbnails';
    case VIDEO_SPLITTING = 'video_splitting';
    case ANALYZING_FACES_INITIAL = 'analyzing_faces_initial';
    case REFINING_EXTRACTION = 'refining_extraction';
    case REFINING_SPLITTING = 'refining_splitting';
    case ANALYZING_FACES_REFINEMENT = 'analyzing_faces_refinement';
    case MERGING_SCENES = 'merging_scenes';
    case TAGGING_SCENES = 'tagging_scenes';
    case CHAPTER_GENERATION = 'chapter_generation';
    case EXPORTING_JELLYFIN = 'exporting_jellyfin';
    case COMPLETED = 'completed';
    case ERROR = 'error';
}