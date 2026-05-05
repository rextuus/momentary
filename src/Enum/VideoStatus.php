<?php

declare(strict_types=1);

namespace App\Enum;

enum VideoStatus: string
{
    case PENDING = 'pending';
    case DOWNLOADING = 'downloading';
    case SCENE_DETECTION = 'scene_detection';
    case SPLITTING = 'splitting';
    case ANALYZING_FACES = 'analyzing_faces';
    case COMPLETED = 'completed';
    case ERROR = 'error';
}
