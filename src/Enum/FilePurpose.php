<?php

namespace App\Enum;

enum FilePurpose: string
{
    case VIDEO_SOURCE = 'video_source';
    case VIDEO_CONVERTED = 'video_converted';
    case VIDEO_FRAME = 'video_frame';
    case THUMBNAIL_SCENE = 'thumbnail_scene';
    case THUMBNAIL_VIDEO = 'thumbnail_video';
    case IMAGE_PROFILE = 'image_profile';
    case IMAGE_FACE = 'image_face';
}