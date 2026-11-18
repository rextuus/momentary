<?php

declare(strict_types=1);

namespace App\Command\Faces;

use App\Repository\VideoRepository;
use App\Service\Face\Benchmark\FaceBenchmark;
use App\Service\Face\Benchmark\FaceBenchmarkReport;
use App\Service\Face\FaceRecognitionSettingsProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ein Video manuell durch die Gesichtserkennung (InsightFace) schicken und messen: Laufzeit je Frame, Gesichter,
 * Verteilung der besten Ähnlichkeiten und was bei den aktuellen Schwellen zugeordnet, vorgeschlagen oder unbekannt
 * wäre. Standard: nur messen, nichts speichern.
 */
#[AsCommand(
    name: 'app:face:analyze',
    description: 'Gesichtserkennung (InsightFace) für ein Video messen; mit --save die Gesichter des Videos ersetzen',
)]
final class AnalyzeFacesCommand extends Command
{
    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly FaceBenchmark $benchmark,
        private readonly FaceRecognitionSettingsProvider $settingsProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('videoId', InputArgument::REQUIRED, 'ID des Videos')
            ->addOption('fps', null, InputOption::VALUE_REQUIRED, 'Frames pro Sekunde (Standard: wie die Pipeline)')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'fps oder scenes (ein Frame je Szene)', 'fps')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'höchstens so viele Frames')
            ->addOption(
                'two-stage',
                null,
                InputOption::VALUE_OPTIONAL,
                'zusätzlich den Vorfilter mit dieser Detektionsgröße messen und ein-/zweistufig vergleichen',
                false,
            )
            ->addOption('save', null, InputOption::VALUE_NONE, 'Gesichter des Videos ersetzen und konsolidieren')
            ->addOption('frames', null, InputOption::VALUE_NONE, 'Tabelle mit allen Frames ausgeben');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $video = $this->videoRepository->find((int) $input->getArgument('videoId'));
        if ($video === null) {
            $io->error('Video nicht gefunden.');

            return Command::FAILURE;
        }

        $mode = (string) $input->getOption('mode');
        if (!\in_array($mode, [FaceBenchmark::MODE_FPS, FaceBenchmark::MODE_SCENES], true)) {
            $io->error('--mode muss fps oder scenes sein.');

            return Command::INVALID;
        }
        $twoStage = $input->getOption('two-stage');
        // --two-stage ohne Wert = 320 (so groß wie im Vorfilter der Pipeline vorgesehen)
        $prefilter = $twoStage === false ? null : (int) ($twoStage ?? 320);
        $save = (bool) $input->getOption('save');
        // Nur interaktiv nachfragen; mit --no-interaction (Skripte) wird wie bei doctrine:migrations:migrate ausgeführt
        if (
            $save
            && $input->isInteractive()
            && !$io->confirm('Die bisherigen Gesichter des Videos werden ersetzt. Fortfahren?', false)
        ) {
            return Command::SUCCESS;
        }

        $io->title(sprintf('Gesichtserkennung für Video %d "%s"', (int) $video->getId(), (string) $video->getTitle()));
        $progress = $io->createProgressBar();
        $report = $this->benchmark->run(
            $video,
            $input->getOption('fps') !== null ? (float) $input->getOption('fps') : null,
            $mode,
            $input->getOption('limit') !== null ? (int) $input->getOption('limit') : null,
            $prefilter,
            $save,
            static function (int $done, int $total) use ($progress): void {
                $progress->setMaxSteps($total);
                $progress->setProgress($done);
            },
        );
        $progress->finish();
        $io->newLine(2);

        if ($input->getOption('frames')) {
            $this->printFrames($io, $report);
        }
        $this->printSummary($io, $report);
        $this->printSimilarities($io, $report);
        $io->success($save ? 'Gesichter gespeichert und konsolidiert.' : 'Nur gemessen, nichts gespeichert.');

        return Command::SUCCESS;
    }

    private function printFrames(SymfonyStyle $io, FaceBenchmarkReport $report): void
    {
        $io->table(
            [
                'Zeit',
                'gesamt ms',
                'detect',
                'embed',
                'warten',
                'Abgleich',
                'Gesichter',
                'zu klein',
                'über Limit',
                'Vorfilter',
            ],
            array_map(static fn(array $f): array => [
                gmdate('H:i:s', $f['timestamp']),
                (int) round($f['wallMs']),
                (int) round($f['detectMs']),
                (int) round($f['embedMs']),
                (int) round($f['waitMs']),
                (int) round($f['matchMs']),
                $f['faces'],
                $f['tooSmall'],
                $f['overLimit'],
                $f['prefilterMs'] !== null
                    ? sprintf('%d ms, %d', (int) round($f['prefilterMs']), (int) $f['prefilterFaces'])
                    : '–',
            ], $report->frames()),
        );
    }

    private function printSummary(SymfonyStyle $io, FaceBenchmarkReport $report): void
    {
        $t = $report->totals();
        $perFrame = static fn(float $ms): string => $t['frames'] > 0
            ? sprintf('%.0f ms/Frame', $ms / $t['frames'])
            : '–';
        $rows = [
            ['Modell', $report->model() ?? '–'],
            ['Frames', sprintf('%d, davon %d mit Gesicht', $t['frames'], $t['framesWithFaces'])],
            ['Gesichter', $report->faceCount()],
            ['Laufzeit gesamt', sprintf('%.1f s (%s)', $t['wallMs'] / 1000, $perFrame($t['wallMs']))],
            ['davon Inferenz', sprintf('%.1f s (%s)', $t['analyzeMs'] / 1000, $perFrame($t['analyzeMs']))],
        ];
        if ($t['twoStageMs'] !== null) {
            $rows[] = [
                sprintf('zweistufig (Vorfilter %d)', (int) $report->prefilterDetSize),
                sprintf(
                    '%.1f s statt %.1f s; %d Frames übersprungen, dabei %d Frames mit %d Gesichtern verpasst',
                    $t['twoStageMs'] / 1000,
                    $t['analyzeMs'] / 1000,
                    (int) $t['skippedFrames'],
                    (int) $t['missedFrames'],
                    (int) $t['missedFaces'],
                ),
            ];
        }
        $io->section('Zusammenfassung');
        $io->table(['', ''], $rows);
    }

    private function printSimilarities(SymfonyStyle $io, FaceBenchmarkReport $report): void
    {
        $thresholds = $this->settingsProvider->thresholds();
        $io->section(sprintf(
            'Beste Ähnlichkeit je Gesicht zu einer vorhandenen Person (zuordnen ab %.2f, vorschlagen ab %.2f)',
            $thresholds->match / 100,
            $thresholds->suggestion / 100,
        ));
        $histogram = $report->histogram();
        $max = max([1, ...array_values($histogram)]);
        $rows = [];
        foreach ($histogram as $bin => $count) {
            $rows[] = [$bin, $count, str_repeat('█', (int) ceil(($count / $max) * 40))];
        }
        $io->table(['Kosinus', 'Gesichter', ''], $rows);

        $faces = max(1, $report->faceCount());
        $io->table(
            ['Bei den aktuellen Schwellen', 'Gesichter', 'Anteil'],
            array_map(
                static fn(string $outcome, int $count): array => [
                    $outcome,
                    $count,
                    sprintf('%d %%', (int) round(($count / $faces) * 100)),
                ],
                array_keys($report->outcomes()),
                array_values($report->outcomes()),
            ),
        );
    }
}
