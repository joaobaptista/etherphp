<?php

namespace App\Enums;

enum MemorySourceEnum: string
{
    case Upload = 'UPLOAD';
    case GooglePhotos = 'GOOGLE_PHOTOS';
    case Icloud = 'ICLOUD';
    case Api = 'API';
}
