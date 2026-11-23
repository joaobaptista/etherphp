<?php

namespace App\Enums;

enum EncryptionModeEnum: string
{
    case SingleBlockGcm = 'SINGLE_BLOCK_GCM';
    case ChunkedAead = 'CHUNKED_AEAD';
    case ClientChunkedV1 = 'CLIENT_CHUNKED_V1';
}
