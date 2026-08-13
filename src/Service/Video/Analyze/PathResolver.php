<?php

declare(strict_types=1);

namespace App\Service\Video\Analyze;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

class PathResolver
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function resolvePath(string $path): string
    {
        // Wenn der Pfad bereits existiert, ist alles gut
        if (file_exists($path)) {
            return $path;
        }

        // Falls er absolut ist und aus Docker stammt
        if (str_starts_with($path, '/var/www/html/')) {
            $relativePath = str_replace('/var/www/html/', '', $path);
            $localPath = $this->projectDir . '/' . $relativePath;

            if (file_exists($localPath)) {
                return $localPath;
            }
        }

        // Falls er bereits relativ ist (oder wir ihn relativ gemacht haben)
        $cleanPath = ltrim($path, '/');

        // Wenn der Pfad mit "public/" beginnt, versuchen wir es auch ohne "public/",
        // da im Docker-Kontext das "public/" oft das Root-Verzeichnis des Webservers ist
        // und Dateien relativ zum Projektroot in "public/..." liegen.
        $pathsToTry = [
            $this->projectDir . '/' . $cleanPath,
            $this->projectDir . '/public/uploads/' . $cleanPath,
        ];

        if (str_starts_with($cleanPath, 'public/')) {
            $pathsToTry[] = $this->projectDir . '/' . substr($cleanPath, 7);
        }

        foreach ($pathsToTry as $projectPath) {
            if (file_exists($projectPath)) {
                return $projectPath;
            }
        }

        return $path;
    }

    public function getProjectDir(): string
    {
        return $this->projectDir;
    }
}
