<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Enum;

enum VideoWorkflowProcessTransition: string
{
    case START_CONVERSION = 'start_conversion';
    case START_SCENE_DETECTION = 'start_scene_detection';
    case START_EXTRACTING_THUMBNAILS = 'start_extracting_thumbnails';
    case START_SPLITTING = 'start_splitting';
    case START_ANALYZING = 'start_analyzing';
    case START_REFINING_EXTRACTION = 'start_refining_extraction';
    case START_REFINING_SPLITTING = 'start_refining_splitting';
    case START_REFINING_ANALYSIS = 'start_refining_analysis';
    case START_MERGING = 'start_merging';
    case START_TAGGING = 'start_tagging';
    case START_CHAPTER_GENERATION = 'start_chapter_generation';
    case START_EXPORT = 'start_export';
    case COMPLETE = 'complete';
    case FAIL = 'fail';
    case RESET = 'reset';
}