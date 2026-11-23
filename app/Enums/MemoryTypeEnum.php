<?php

namespace App\Enums;

enum MemoryTypeEnum: string
{
    case Text = 'TEXT';
    case Image = 'IMAGE';
    case Video = 'VIDEO';
    case Audio = 'AUDIO';
}
