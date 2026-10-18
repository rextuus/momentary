<?php

declare(strict_types=1);

namespace App\Command\Transcription;

use App\Repository\VideoRepository;
use App\Service\Storage\StoragePathProvider;
use App\Service\Transcription\AudioExtractor;
use App\Service\Transcription\TranscriptionSettingsProvider;
use App\Service\Transcription\WhisperRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Misst, wie schnell die Transkription auf diesem Rechner läuft, und rechnet auf eine Stunde Video hoch.
 */
#[AsCommand(
    name: 'app:transcription:benchmark',
    description: 'Misst die Transkriptions-Geschwindigkeit auf diesem Rechner',
)]
final class TranscriptionBenchmarkCommand extends Command
{
    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly StoragePathProvider $pathProvider,
        private readonly AudioExtractor $audioExtractor,
        private readonly WhisperRunner $whisper,
        private readonly TranscriptionSettingsProvider $settingsProvider,
        #[Autowire('%kernel.project_dir%/var/transcription')]
        private readonly string $workDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('videoId', InputArgument::REQUIRED, 'Video mit Sprache, z. B. eines mit vielen Gesprächen')
            ->addOption('seconds', null, InputOption::VALUE_REQUIRED, 'So viele Sekunden vom Anfang messen', '120')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Modell (Standard: aus den Einstellungen)')
            ->addOption('threads', null, InputOption::VALUE_REQUIRED, 'Threads (Standard: aus den Einstellungen)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $video = $this->videoRepository->find((int) $input->getArgument('videoId'));
        $file = $video?->getConvertedFile() ?? $video?->getSourceFile();
        if ($file === null || !is_file($this->pathProvider->getAbsolutePath($file))) {
            $io->error('Video oder Videodatei nicht gefunden.');

            return Command::FAILURE;
        }

        $settings = clone $this->settingsProvider->get();
        if ($input->getOption('model') !== null) {
            $settings->setModel((string) $input->getOption('model'));
        }
        if ($input->getOption('threads') !== null) {
            $settings->setThreads((int) $input->getOption('threads'));
        }

        new Filesystem()->mkdir($this->workDir);
        $wav = $this->workDir . '/benchmark.wav';
        try {
            $audioSeconds = $this->audioExtractor->extract(
                $this->pathProvider->getAbsolutePath($file),
                $wav,
                max(10, (int) $input->getOption('seconds')),
            );

            $io->text(sprintf(
                'Transkribiere %.0f s Ton mit %s, %d Thread(s) … (beim ersten Mal wird das Modell heruntergeladen)',
                $audioSeconds,
                $settings->getModel(),
                $settings->getThreads(),
            ));
            $start = microtime(true);
            $result = $this->whisper->run($wav, $settings);
            $took = microtime(true) - $start;
        } finally {
            new Filesystem()->remove($wav);
        }

        $factor = $took / max(1.0, $audioSeconds);
        $io->table(['', ''], [
            ['Modell', $settings->getModel()],
            ['Threads', $settings->getThreads()],
            ['Ton', sprintf('%.0f s', $audioSeconds)],
            ['Rechenzeit (inkl. Laden des Modells)', sprintf('%.0f s', $took)],
            ['Faktor', sprintf('%.2f × Echtzeit', $factor)],
            ['Hochgerechnet auf 1 h Video', sprintf('ca. %d min', (int) round(60 * $factor))],
            ['Abschnitte', \count($result['segments'])],
        ]);
        foreach (\array_slice($result['segments'], 0, 5) as $segment) {
            $io->text(sprintf('  %6.1f s  %s', $segment['start'], $segment['text']));
        }

        return Command::SUCCESS;
    }
}
