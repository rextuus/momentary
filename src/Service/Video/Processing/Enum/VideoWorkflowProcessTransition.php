<?php

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
    case START_OPTIMIZATION = 'start_optimization';
    case START_TAGGING = 'start_tagging';
    case START_CHAPTER_GENERATION = 'start_chapter_generation';
    case COMPLETE = 'complete';
    case FAIL = 'fail';
    case START_ANALYZING_SCENES = 'start_analyzing_scenes';

    // Rücksprünge / Resets
    case BACK_TO_PENDING = 'back_to_pending';
    case BACK_TO_CONVERSION = 'back_to_conversion';
    case BACK_TO_SCENE_DETECTION = 'back_to_scene_detection';
    case BACK_TO_SPLITTING = 'back_to_splitting';
    case BACK_TO_REFINING_EXTRACTION = 'back_to_refining_extraction';
    case RESET = 'reset';
}