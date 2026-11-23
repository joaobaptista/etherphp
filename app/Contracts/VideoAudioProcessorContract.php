<?php

namespace App\Contracts;

interface VideoAudioProcessorContract
{
    public function isAvailable(): bool;

    /**
     * @return array{durationSeconds: int, posterBytes: ?string, outputPath: string}
     */
    public function processVideo(string $inputPath, string $outputPath): array;

    /**
     * @return array{durationSeconds: int, waveformPeaks: list<float>, outputPath: string}
     */
    public function processAudio(string $inputPath, string $outputPath): array;
}
