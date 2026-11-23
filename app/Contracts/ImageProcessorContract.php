<?php

namespace App\Contracts;

interface ImageProcessorContract
{
    /**
     * @return array{
     *     master: array{buffer: string, width: int, height: int, sizeBytes: int},
     *     display: array{buffer: string, width: int, height: int, sizeBytes: int},
     *     thumb: array{buffer: string, width: int, height: int, sizeBytes: int}
     * }
     */
    public function processMultiTierWebp(string $rawBytes): array;

    public function normalizeSquarePhoto(string $rawBytes, int $maxSize = 800, int $quality = 88): string;

    /**
     * @return array{buffer: string, mimeType: string, filename: string}
     */
    public function exportForPrint(string $masterBytes, string $format = 'png', string $baseFilename = 'ethernity-memory'): array;
}
