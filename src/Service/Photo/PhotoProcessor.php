<?php

declare(strict_types=1);

namespace App\Service\Photo;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Ruft video-analyzer/python/process_photo.py auf: drehen, verkleinern, EXIF lesen (auch HEIC).
 */
class PhotoProcessor
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(PYTHON_BINARY)%')]
        private readonly string $pythonBinary = '/usr/bin/python3',
    ) {}

    /**
     * @return array{width: ?int, height: ?int, takenAt: ?\DateTimeImmutable, latitude: ?float, longitude: ?float, camera: ?string}
     */
    public function process(string $sourcePath, string $displayPath, string $thumbnailPath): array
    {
        $process = new Process([
            is_file($this->pythonBinary) ? $this->pythonBinary : 'python3',
            $this->projectDir . '/video-analyzer/python/process_photo.py',
            '--input',
            $sourcePath,
            '--display',
            $displayPath,
            '--thumbnail',
            $thumbnailPath,
        ]);
        $process->setTimeout(300);
        $process->run();

        $data = json_decode($process->getOutput(), true);
        if (!$process->isSuccessful() || !\is_array($data)) {
            throw new \RuntimeException(
                'Foto konnte nicht gelesen werden: ' . trim($process->getErrorOutput() ?: $process->getOutput()),
            );
        }

        $takenAt = null;
        if (\is_string($data['takenAt'] ?? null)) {
            try {
                // Ohne Zeitzone in den EXIF-Daten ist es die Uhrzeit der Kamera, also Ortszeit
                $takenAt = new \DateTimeImmutable($data['takenAt'], new \DateTimeZone('Europe/Berlin'));
            } catch (\Exception) {
                $takenAt = null;
            }
        }

        return [
            'width' => isset($data['width']) ? (int) $data['width'] : null,
            'height' => isset($data['height']) ? (int) $data['height'] : null,
            'takenAt' => $takenAt?->setTimezone(new \DateTimeZone('UTC')),
            'latitude' => isset($data['latitude']) ? (float) $data['latitude'] : null,
            'longitude' => isset($data['longitude']) ? (float) $data['longitude'] : null,
            'camera' => isset($data['camera']) ? (string) $data['camera'] : null,
        ];
    }
}
