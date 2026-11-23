<?php

namespace App\Services;

use App\Contracts\VideoAudioProcessorContract;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class FfmpegVideoAudioProcessorService implements VideoAudioProcessorContract
{
    public function isAvailable(): bool
    {
        $finder = new ExecutableFinder;

        return $finder->find('ffmpeg') !== null;
    }

    /**
     * @return array{durationSeconds: int, posterBytes: ?string, outputPath: string}
     */
    public function processVideo(string $inputPath, string $outputPath): array
    {
        if (! $this->isAvailable()) {
            copy($inputPath, $outputPath);

            return [
                'durationSeconds' => 0,
                'posterBytes' => null,
                'outputPath' => $outputPath,
            ];
        }

        $transcode = new Process([
            'ffmpeg', '-y', '-i', $inputPath,
            '-c:v', 'libx264', '-preset', 'fast', '-crf', '23',
            '-c:a', 'aac', '-b:a', '128k',
            '-movflags', '+faststart',
            $outputPath,
        ]);
        $transcode->setTimeout(240);
        $transcode->run();

        if (! $transcode->isSuccessful() || ! is_file($outputPath)) {
            copy($inputPath, $outputPath);
        }

        $posterPath = $outputPath.'.poster.webp';
        $posterBytes = null;
        $posterProc = new Process([
            'ffmpeg', '-y', '-ss', '00:00:01', '-i', $outputPath,
            '-vframes', '1', '-q:v', '80', $posterPath,
        ]);
        $posterProc->setTimeout(30);
        $posterProc->run();

        if ($posterProc->isSuccessful() && is_file($posterPath)) {
            $raw = file_get_contents($posterPath);
            $posterBytes = $raw !== false ? $raw : null;
            @unlink($posterPath);
        }

        return [
            'durationSeconds' => $this->probeDuration($outputPath),
            'posterBytes' => $posterBytes,
            'outputPath' => $outputPath,
        ];
    }

    /**
     * @return array{durationSeconds: int, waveformPeaks: list<float>, outputPath: string}
     */
    public function processAudio(string $inputPath, string $outputPath): array
    {
        if (! $this->isAvailable()) {
            copy($inputPath, $outputPath);

            return [
                'durationSeconds' => 0,
                'waveformPeaks' => array_fill(0, 100, 0.2),
                'outputPath' => $outputPath,
            ];
        }

        $transcode = new Process([
            'ffmpeg', '-y', '-i', $inputPath,
            '-c:a', 'aac', '-ar', '48000', '-b:a', '128k',
            $outputPath,
        ]);
        $transcode->setTimeout(180);
        $transcode->run();

        if (! $transcode->isSuccessful() || ! is_file($outputPath)) {
            copy($inputPath, $outputPath);
        }

        return [
            'durationSeconds' => $this->probeDuration($outputPath),
            'waveformPeaks' => array_fill(0, 100, 0.25),
            'outputPath' => $outputPath,
        ];
    }

    private function probeDuration(string $filePath): int
    {
        $finder = new ExecutableFinder;
        if ($finder->find('ffprobe') === null) {
            return 0;
        }

        $probe = new Process([
            'ffprobe', '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $filePath,
        ]);
        $probe->setTimeout(15);
        $probe->run();

        if (! $probe->isSuccessful()) {
            return 0;
        }

        return max(0, (int) round((float) trim($probe->getOutput())));
    }
}
