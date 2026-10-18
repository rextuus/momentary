<?php

declare(strict_types=1);

namespace App\Enum;

enum FilePurpose: string
{
    case VIDEO_SOURCE = 'video_source';
    case VIDEO_CONVERTED = 'video_converted';
    /** kleinere Fassung zum Streamen über das Internet (Web-Fassung) */
    case VIDEO_WEB = 'video_web';
    case VIDEO_FRAME = 'video_frame';
    case THUMBNAIL_SCENE = 'thumbnail_scene';
    case THUMBNAIL_VIDEO = 'thumbnail_video';
    case IMAGE_PROFILE = 'image_profile';
    case IMAGE_FACE = 'image_face';
    case PHOTO_SOURCE = 'photo_source';
    case PHOTO_DISPLAY = 'photo_display';
    case PHOTO_THUMBNAIL = 'photo_thumbnail';
}
