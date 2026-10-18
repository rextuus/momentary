<?php

declare(strict_types=1);

namespace App\Service\Video\Analyze;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Erzeugt eine browser- und Jellyfin-taugliche MP4-Datei.
 *
 * Ist das Video schon H.264 (8 Bit, 4:2:0) mit AAC/MP3-Ton, wird nur der Container umgepackt: das dauert
 * Sekunden statt Minuten und behält die Originalqualität. Nur andere Formate (z. B. MPEG-2, DV, MJPEG,
 * 10-Bit-Material) werden neu kodiert.
 */
class Mp4Converter
{
    private const array COPYABLE_AUDIO = ['aac', 'mp3'];

    /** Mindestlaufzeit für ffmpeg; bei längeren Videos wächst das Limit mit der Videolänge. */
    private const int MIN_TIMEOUT_SECONDS = 1800;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function convertToMp4(string $sourcePath, string $targetPath): bool
    {
        $probe = $this->probe($sourcePath);
        $timeout = max(self::MIN_TIMEOUT_SECONDS, (int) ceil($probe['duration'] * 4));

        if (self::canBeRemuxed($probe)) {
            if ($this->run($this->remuxCommand($sourcePath, $targetPath), $timeout)) {
                $this->logger->info(sprintf('Mp4Converter: "%s" ohne Neukodierung umgepackt.', basename($sourcePath)));

                return true;
            }
            $this->logger->warning(sprintf(
                'Mp4Converter: Umpacken von "%s" fehlgeschlagen, kodiere neu.',
                basename($sourcePath),
            ));
        }

        return $this->run($this->reencodeCommand($sourcePath, $targetPath), $timeout);
    }

    /**
     * @param array{video: ?array{codec: string, pixFmt: string}, audio: list<string>, duration: float} $probe
     */
    public static function canBeRemuxed(array $probe): bool
    {
        $video = $probe['video'];
        if (
            $video === null
            || $video['codec'] !== 'h264'
            || !\in_array($video['pixFmt'], ['yuv420p', 'yuvj420p'], true)
        ) {
            return false;
        }

        return array_diff($probe['audio'], self::COPYABLE_AUDIO) === [];
    }

    /**
     * @return array{video: ?array{codec: string, pixFmt: string}, audio: list<string>, duration: float, createdAt?: ?string, location?: ?string, camera?: ?string}
     */
    public function probe(string $path): array
    {
        $process = new Process([
            'ffprobe',
            '-v',
            'error',
            '-show_entries',
            'stream=codec_type,codec_name,pix_fmt:format=duration:format_tags=creation_time,location,com.apple.quicktime.location.ISO6709,com.apple.quicktime.make,com.apple.quicktime.model,com.android.manufacturer,com.android.model,make,model',
            '-of',
            'json',
            $path,
        ]);
        $process->setTimeout(60);
        $process->run();

        $data = json_decode($process->getOutput(), true);
        $result = ['video' => null, 'audio' => [], 'duration' => 0.0];
        if (!\is_array($data)) {
            return $result;
        }

        foreach ($data['streams'] ?? [] as $stream) {
            $type = $stream['codec_type'] ?? null;
            if ($type === 'video' && $result['video'] === null) {
                $result['video'] = [
                    'codec' => (string) ($stream['codec_name'] ?? ''),
                    'pixFmt' => (string) ($stream['pix_fmt'] ?? ''),
                ];
            } elseif ($type === 'audio') {
                $result['audio'][] = (string) ($stream['codec_name'] ?? '');
            }
        }
        $result['duration'] = (float) ($data['format']['duration'] ?? 0.0);
        $result['createdAt'] = isset($data['format']['tags']['creation_time'])
            ? (string) $data['format']['tags']['creation_time']
            : null;
        // GPS im ISO-6709-Format, z. B. "+39.5700+002.6500+012.000/" (iPhone, viele Android-Geräte)
        $tags = (array) ($data['format']['tags'] ?? []);
        $location = $tags['com.apple.quicktime.location.ISO6709'] ?? $tags['location'] ?? null;
        $result['location'] = \is_string($location) ? $location : null;
        $result['camera'] = self::cameraName($tags);

        return $result;
    }

    /**
     * Gerät laut Metadaten (iPhone, Android), z. B. "Apple iPhone 13"; viele Action-Cams schreiben nichts.
     *
     * @param array<mixed> $tags
     */
    public static function cameraName(array $tags): ?string
    {
        $make = trim(
            (string) ($tags['com.apple.quicktime.make'] ?? $tags['com.android.manufacturer'] ?? $tags['make'] ?? ''),
        );
        $model = trim(
            (string) ($tags['com.apple.quicktime.model'] ?? $tags['com.android.model'] ?? $tags['model'] ?? ''),
        );
        // Wie bei Fotos: "Apple iPhone 13", aber nicht "Samsung Samsung SM-G991B"
        if ($make !== '' && str_starts_with(strtolower($model), strtolower($make))) {
            $make = '';
        }
        $name = trim($make . ' ' . $model);

        return $name === '' ? null : mb_substr($name, 0, 128);
    }

    /**
     * @return list<string>
     */
    private function remuxCommand(string $sourcePath, string $targetPath): array
    {
        // Nur Bild und Ton übernehmen: Kameras legen oft Timecode-/Datenspuren bei, die MP4 nicht aufnimmt
        return [
            'ffmpeg',
            '-y',
            '-i',
            $sourcePath,
            '-map',
            '0:v:0',
            '-map',
            '0:a?',
            // Aufnahmedatum (creation_time) und andere Metadaten des Originals behalten
            '-map_metadata',
            '0',
            '-c',
            'copy',
            '-movflags',
            '+faststart',
            $targetPath,
        ];
    }

    /**
     * @return list<string>
     */
    private function reencodeCommand(string $sourcePath, string $targetPath): array
    {
        return [
            'ffmpeg',
            '-y',
            '-i',
            $sourcePath,
            '-map',
            '0:v:0',
            '-map',
            '0:a?',
            // Aufnahmedatum (creation_time) und andere Metadaten des Originals behalten
            '-map_metadata',
            '0',
            '-c:v',
            'libx264',
            '-preset',
            'ultrafast',
            '-crf',
            '23',
            '-pix_fmt',
            'yuv420p',
            '-c:a',
            'aac',
            '-movflags',
            '+faststart',
            $targetPath,
        ];
    }

    /**
     * @param list<string> $command
     */
    private function run(array $command, int $timeout): bool
    {
        $process = new Process($command);
        $process->setTimeout($timeout);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->logger->error(sprintf('FFmpeg conversion failed: %s', $process->getErrorOutput()));
        }

        return $process->isSuccessful();
    }
}
