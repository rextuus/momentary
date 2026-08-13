<?php

declare(strict_types=1);

namespace App\Service\Video\Analyze;

use Symfony\Component\Process\Process;

class Mp4Converter
{
    public function convertToMp4(string $sourcePath, string $targetPath): bool
    {
        $process = new Process([
            'ffmpeg', '-y', '-i', $sourcePath,
            '-c:v', 'libx264', '-preset', 'ultrafast', '-crf', '23',
            '-c:a', 'aac', $targetPath
        ]);
        $process->setTimeout(1800);
        $process->run();

        return $process->isSuccessful();
    }
}
