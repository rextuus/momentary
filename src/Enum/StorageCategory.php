<?php

namespace App\Enum;

enum StorageCategory: string
{
    case VIDEOS = 'videos';
    case FRAMES = 'frames';
    case THUMBNAILS = 'thumbnails';
    case IMAGES_PROFILES = 'images/profiles';
    case JELLYFIN_UPLOADS = 'jellyfin/uploads';
}