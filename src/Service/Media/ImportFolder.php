<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Entity\User;
use App\Message\ImportMediaFileMessage;
use App\Service\Storage\StoragePathProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Import-Ordner (`storage/imports`, per SFTP unter `upload/import` erreichbar): Videos und Fotos ablegen, auch in
 * Unterordnern; der erste Unterordner wird zum Gruppen-Tag.
 *
 * Übernommene Dateien verschwinden aus dem Ordner (Videos werden in den Storage verschoben, Fotos kopiert und dann
 * gelöscht). Schon vorhandene Dateien wandern nach `_doppelt/`, damit nichts ungefragt gelöscht wird.
 */
class ImportFolder
{
    public const string DUPLICATES_DIR = '_doppelt';

    /** So lange muss eine Datei unverändert sein, bevor sie als fertig hochgeladen gilt */
    private const int SETTLE_SECONDS = 120;

    public function __construct(
        private readonly StoragePathProvider $pathProvider,
        private readonly MediaImporter $importer,
        private readonly MessageBusInterface $bus,
        private readonly KnownMedia $knownMedia,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(bool:IMPORT_FOLDER_WATCH)%')]
        private readonly bool $watch = false,
    ) {}

    public function root(): string
    {
        return $this->pathProvider->getImportAbsolutePath();
    }

    /** Wird der Ordner automatisch überwacht (alle 5 Minuten)? */
    public function isWatched(): bool
    {
        return $this->watch;
    }

    /**
     * Unterstützte Dateien, nach Pfad sortiert.
     *
     * @return list<ImportFile>
     */
    public function files(): array
    {
        if (!is_dir($this->root())) {
            return [];
        }

        $known = $this->knownMedia->load();
        $settled = time() - self::SETTLE_SECONDS;
        $files = [];
        $finder = new Finder()
            ->files()
            ->in($this->root())
            ->exclude(self::DUPLICATES_DIR)
            ->ignoreDotFiles(true)
            ->sortByName(true);
        foreach ($finder as $file) {
            $type = MediaImporter::typeOf($file->getFilename());
            if ($type === null) {
                continue;
            }
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $folder = explode('/', $relative)[0];
            $files[] = new ImportFile(
                $relative,
                $type,
                (int) $file->getSize(),
                new \DateTimeImmutable('@' . $file->getMTime()),
                str_contains($relative, '/') ? $folder : null,
                $file->getMTime() < $settled,
                $known->matches($file->getFilename(), (int) $file->getSize()),
            );
        }

        return $files;
    }

    /**
     * Dateien zum Import einplanen (eine Nachricht je Datei, verarbeitet vom Worker).
     *
     * @param list<string>|null $only relative Pfade; null = alle fertig hochgeladenen, die nicht vermutlich schon
     *                                vorhanden sind (die übernimmt nur, wer sie ausdrücklich auswählt)
     *
     * @return int Anzahl eingeplanter Dateien
     */
    public function queue(?User $owner = null, ?array $only = null): int
    {
        $count = 0;
        foreach ($this->files() as $file) {
            $wanted = $only === null
                ? $file->ready && !$file->likelyDuplicate
                : \in_array($file->relativePath, $only, true);
            if (!$wanted) {
                continue;
            }
            $this->bus->dispatch(new ImportMediaFileMessage($file->relativePath, $owner?->getId()));
            $count++;
        }

        return $count;
    }

    /**
     * Eine Datei übernehmen. null, wenn sie inzwischen nicht mehr da ist (z. B. schon übernommen).
     */
    public function import(string $relativePath, ?User $owner = null): ?MediaImportResult
    {
        $path = $this->resolve($relativePath);
        if ($path === null) {
            return null;
        }

        $folder = explode('/', $relativePath)[0];
        $result = $this->importer->import(
            $path,
            basename($relativePath),
            $owner,
            str_contains($relativePath, '/') ? $folder : null,
        );

        $filesystem = new Filesystem();
        if ($result->duplicate) {
            $target = $this->duplicateTarget($relativePath);
            $filesystem->mkdir(\dirname($target));
            $filesystem->rename($path, $target);
        } elseif (is_file($path)) {
            // Fotos werden kopiert; Videos hat der Import schon verschoben
            $filesystem->remove($path);
        }
        $this->logger->info(sprintf(
            'Import-Ordner: %s %s',
            $relativePath,
            $result->duplicate ? 'schon vorhanden' : 'übernommen',
        ));

        return $result;
    }

    /**
     * Absoluter Pfad zu einer Datei im Import-Ordner, null wenn es sie nicht (mehr) gibt oder der Pfad hinausführt.
     */
    private function resolve(string $relativePath): ?string
    {
        $root = realpath($this->root());
        $path = realpath($this->root() . '/' . $relativePath);
        if (
            $root === false
            || $path === false
            || !is_file($path)
            || !str_starts_with($path, $root . \DIRECTORY_SEPARATOR)
            || str_starts_with($relativePath, self::DUPLICATES_DIR . '/')
        ) {
            return null;
        }

        return $path;
    }

    private function duplicateTarget(string $relativePath): string
    {
        $target = $this->root() . '/' . self::DUPLICATES_DIR . '/' . $relativePath;
        if (file_exists($target)) {
            $info = pathinfo($target);
            $target = sprintf(
                '%s/%s-%s.%s',
                $info['dirname'],
                $info['filename'],
                date('YmdHis'),
                $info['extension'] ?? '',
            );
        }

        return $target;
    }
}
