<?php

namespace App\Services;

use App\Contracts\ImageProcessorContract;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageManagerInterface;
use RuntimeException;

class InterventionImageProcessorService implements ImageProcessorContract
{
    private ImageManagerInterface $manager;

    public function __construct()
    {
        $driver = extension_loaded('imagick') ? ImagickDriver::class : GdDriver::class;
        $this->manager = ImageManager::usingDriver($driver);
    }

    /**
     * @return array{
     *     master: array{buffer: string, width: int, height: int, sizeBytes: int},
     *     display: array{buffer: string, width: int, height: int, sizeBytes: int},
     *     thumb: array{buffer: string, width: int, height: int, sizeBytes: int}
     * }
     */
    public function processMultiTierWebp(string $rawBytes): array
    {
        $base = $this->manager->decodeBinary($rawBytes);
        $base->orient();

        $masterImg = clone $base;
        $masterImg->scaleDown(width: 4096, height: 4096);
        $masterBuf = (string) $masterImg->encodeUsingFormat(Format::WEBP, quality: 92);

        $displayImg = clone $base;
        $displayImg->scaleDown(width: 1920, height: 1080);
        $displayBuf = (string) $displayImg->encodeUsingFormat(Format::WEBP, quality: 85);

        $thumbImg = clone $base;
        $thumbImg->coverDown(width: 400, height: 400);
        $thumbBuf = (string) $thumbImg->encodeUsingFormat(Format::WEBP, quality: 80);

        return [
            'master' => [
                'buffer' => $masterBuf,
                'width' => $masterImg->width(),
                'height' => $masterImg->height(),
                'sizeBytes' => strlen($masterBuf),
            ],
            'display' => [
                'buffer' => $displayBuf,
                'width' => $displayImg->width(),
                'height' => $displayImg->height(),
                'sizeBytes' => strlen($displayBuf),
            ],
            'thumb' => [
                'buffer' => $thumbBuf,
                'width' => $thumbImg->width(),
                'height' => $thumbImg->height(),
                'sizeBytes' => strlen($thumbBuf),
            ],
        ];
    }

    public function normalizeSquarePhoto(string $rawBytes, int $maxSize = 800, int $quality = 88): string
    {
        $img = $this->manager->decodeBinary($rawBytes);
        $img->orient();
        $img->scaleDown(width: $maxSize, height: $maxSize);

        return (string) $img->encodeUsingFormat(Format::WEBP, quality: $quality);
    }

    /**
     * @return array{buffer: string, mimeType: string, filename: string}
     */
    public function exportForPrint(string $masterBytes, string $format = 'png', string $baseFilename = 'ethernity-memory'): array
    {
        $img = $this->manager->decodeBinary($masterBytes);
        $img->setResolution(300, 300);

        $safeBase = preg_replace('/[^A-Za-z0-9._-]/', '-', $baseFilename) ?: 'ethernity-memory';
        $normalizedFormat = strtolower($format);

        return match ($normalizedFormat) {
            'webp' => [
                'buffer' => (string) $img->encodeUsingFormat(Format::WEBP, quality: 98),
                'mimeType' => 'image/webp',
                'filename' => "{$safeBase}-300dpi.webp",
            ],
            'jpg', 'jpeg' => [
                'buffer' => (string) $img->encodeUsingFormat(Format::JPEG, quality: 98),
                'mimeType' => 'image/jpeg',
                'filename' => "{$safeBase}-300dpi.jpg",
            ],
            'png', 'tiff', 'print' => [
                'buffer' => (string) $img->encodeUsingFormat(Format::PNG),
                'mimeType' => 'image/png',
                'filename' => "{$safeBase}-300dpi.png",
            ],
            default => throw new RuntimeException("Unsupported export format: {$format}"),
        };
    }
}
