<?php

declare(strict_types=1);

namespace App\Service\Video\Recording;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Liest Aufnahmezeiten aus den Subcodes einer DV-Datei (video-analyzer/python/read_dv_subcodes.py). Liest die Datei
 * einmal durch, ohne zu dekodieren (DV: rund 13 GB je Stunde).
 */
class DvSubcodeReader
{
    /** Kamerazeit ohne Zone: wie beim Dateinamen als Ortszeit gelesen (documentation/dv_aufnahmedatum.md) */
    public const string CAMERA_TIMEZONE = 'Europe/Berlin';

    private readonly string $pythonBinaryPath;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(PYTHON_BINARY)%')]
        string $pythonBinary = '/usr/bin/python3',
    ) {
        $this->pythonBinaryPath = file_exists($pythonBinary) ? $pythonBinary : 'python3';
    }

    /**
     * @throws \RuntimeException wenn die Datei nicht gelesen werden kann (kein DV, kaputt, Zeitlimit)
     */
    public function read(string $path): DvSubcodes
    {
        $process = new Process([
            $this->pythonBinaryPath,
            '-I',
            $this->projectDir . '/video-analyzer/python/read_dv_subcodes.py',
            $path,
        ]);
        // Großzügig: eine Stunde DV liest sich in wenigen Minuten, begrenzt durch die Platte
        $process->setTimeout(3600);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                'read_dv_subcodes.py failed (exit code %s): %s',
                (string) $process->getExitCode(),
                trim($process->getErrorOutput()),
            ));
        }

        return self::parse($process->getOutput());
    }

    /**
     * @throws \RuntimeException bei unerwarteter Ausgabe
     */
    public static function parse(string $json): DvSubcodes
    {
        $data = json_decode($json, true);
        if (!\is_array($data) || !\is_array($data['runs'] ?? null) || !is_numeric($data['fps'] ?? null)) {
            throw new \RuntimeException('Unexpected output from read_dv_subcodes.py: ' . substr($json, 0, 200));
        }

        $zone = new \DateTimeZone(self::CAMERA_TIMEZONE);
        $runs = [];
        foreach ($data['runs'] as $run) {
            if (!\is_array($run) || !is_numeric($run['start'] ?? null) || !is_numeric($run['end'] ?? null)) {
                throw new \RuntimeException('Unexpected run from read_dv_subcodes.py: ' . json_encode($run));
            }
            $time = \is_string($run['recorded_at'] ?? null)
                ? \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $run['recorded_at'], $zone)
                : null;
            $runs[] = new DvRun(
                (int) $run['start'],
                (int) $run['end'],
                $time ?: null,
                (bool) ($run['rec_start'] ?? false),
            );
        }

        return new DvSubcodes(
            (string) ($data['system'] ?? ''),
            (float) $data['fps'],
            (int) ($data['frames'] ?? 0),
            $runs,
        );
    }
}
