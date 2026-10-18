<?php

declare(strict_types=1);

namespace App\Service\Video\WebVersion;

use App\Entity\File;
use App\Entity\Video;
use App\Service\Storage\FileManager;
use App\Service\Storage\StoragePathProvider;
use App\Service\Video\VideoFiles;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Erzeugt die Web-Fassung eines Videos (Format siehe WebVersionFormat).
 *
 * Die Originale (oft 4K mit 25–90 Mbit/s) passen nicht durch den Upload eines Heimanschlusses; zum Ansehen von
 * unterwegs (Hub auf dem Tablet, Share-Links) wird deshalb diese Fassung gestreamt. Ist das Original schon klein genug,
 * gibt es keine Web-Fassung.
 *
 * Kodiert wird mit der Intel-Grafik (VAAPI), wenn /dev/dri im Container ist, sonst (oder wenn das scheitert) mit
 * libx264 auf der CPU.
 */
class WebVersionEncoder
{
    public function __construct(
        private readonly VideoFiles $videoFiles,
        private readonly FileManager $fileManager,
        private readonly StoragePathProvider $pathProvider,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return string Ergebnis für Log und Fortschrittsanzeige
     */
    public function encode(Video $video, bool $force = false): string
    {
        $source = $this->videoFiles->playablePath($video);
        if ($source === null) {
            return 'Keine abspielbare MP4, Web-Fassung übersprungen.';
        }
        if (!$force && $this->videoFiles->existing($video->getWebFile()) !== null) {
            return 'Web-Fassung ist schon da.';
        }

        $probe = $this->probe($source);
        if (!$force && $probe !== null && WebVersionFormat::smallEnough($probe)) {
            $this->replaceWebFile($video, null);

            return sprintf(
                'Original (%dp, %.1f Mbit/s) ist klein genug, keine Web-Fassung nötig.',
                $probe['shortSide'],
                $probe['bitrate'] / 1e6,
            );
        }

        $file = $video->getWebFile() ?? $this->fileManager->createWebVideoFile($video);
        $target = $this->pathProvider->getAbsolutePath($file);
        $temporary = $target . '.part.mp4';
        new Filesystem()->mkdir(\dirname($target));

        $encoder = is_readable(WebVersionFormat::RENDER_DEVICE)
        && $this->run(WebVersionFormat::vaapiCommand($source, $temporary))
            ? 'Intel-Grafik'
            : null;
        if ($encoder === null && $this->run(WebVersionFormat::softwareCommand($source, $temporary))) {
            $encoder = 'CPU';
        }
        if ($encoder === null) {
            new Filesystem()->remove($temporary);
            throw new \RuntimeException('ffmpeg konnte keine Web-Fassung erzeugen.');
        }

        new Filesystem()->rename($temporary, $target, true);
        $file->setFileSize((int) filesize($target));
        $this->replaceWebFile($video, $file);

        return sprintf(
            'Web-Fassung erstellt (%s): %.0f MB statt %.0f MB.',
            $encoder,
            filesize($target) / 1048576,
            filesize($source) / 1048576,
        );
    }

    /**
     * @param list<string> $command
     */
    private function run(array $command): bool
    {
        $process = new Process($command);
        // 4K-Filme auf schwacher CPU dauern; der Worker hat Zeit
        $process->setTimeout(6 * 3600);
        $process->run();
        if (!$process->isSuccessful()) {
            $this->logger->warning(sprintf(
                'Web-Fassung (%s): %s',
                $command[array_search('-c:v', $command, true) + 1] ?? '?',
                trim($process->getErrorOutput()),
            ));
        }

        return $process->isSuccessful();
    }

    /**
     * @return array{shortSide: int, bitrate: int}|null
     */
    private function probe(string $path): ?array
    {
        $process = new Process([
            'ffprobe',
            '-v',
            'error',
            '-select_streams',
            'v:0',
            '-show_entries',
            'stream=width,height:format=bit_rate',
            '-of',
            'json',
            $path,
        ]);
        $process->setTimeout(60);
        $process->run();
        $data = json_decode($process->getOutput(), true);
        if (!\is_array($data) || !isset($data['streams'][0]['width'], $data['streams'][0]['height'])) {
            return null;
        }

        return [
            'shortSide' => min((int) $data['streams'][0]['width'], (int) $data['streams'][0]['height']),
            'bitrate' => (int) ($data['format']['bit_rate'] ?? 0),
        ];
    }

    private function replaceWebFile(Video $video, ?File $file): void
    {
        $old = $video->getWebFile();
        if ($old !== null && $old !== $file) {
            new Filesystem()->remove($this->pathProvider->getAbsolutePath($old));
            $this->entityManager->remove($old);
        }
        $video->setWebFile($file);
        if ($file !== null) {
            $this->entityManager->persist($file);
        }
        $this->entityManager->flush();
    }
}
