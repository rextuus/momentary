<?php

namespace App\Enum;

enum MimeType: string
{
    case MP4 = 'video/mp4';
    case JPEG = 'image/jpeg';
    case PNG = 'image/png';
}