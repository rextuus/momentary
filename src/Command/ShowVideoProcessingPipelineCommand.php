<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Message\Abstract\AbstractVideoProcessStepMessage;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Finder\Finder;

#[AsCommand(
    name: 'app:video:processing-pipeline:show',
    description: 'Visualisiert die komplette Video-Processing-Pipeline-Kette mit allen Steps.'
)]
class ShowVideoProcessingPipelineCommand extends Command
{
    private string $projectDir;

    public function __construct(
        #[Target('kernel.project_dir')] string $projectDir
    ) {
        parent::__construct();
        $this->projectDir = $projectDir;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Video Processing Pipeline Structure');

        $messageDirectory = $this->projectDir . '/src/Service/Video/Processing/Message';

        if (!is_dir($messageDirectory)) {
            $io->error(sprintf('Das Message-Verzeichnis "%s" wurde nicht gefunden.', $messageDirectory));

            return Command::FAILURE;
        }

        $pipelineSteps = [];

        $finder = new Finder();
        $finder->files()->in($messageDirectory)->name('*.php');

        foreach ($finder as $file) {
            $relativePath = $file->getRelativePathname();
            $className = 'App\\Service\\Video\\Processing\\Message\\' . str_replace(
                    ['/', '.php'],
                    ['\\', ''],
                    $relativePath
                );

            if (!class_exists($className)) {
                continue;
            }

            $reflection = new ReflectionClass($className);

            if ($reflection->isAbstract()) {
                continue;
            }

            $stepOrderAttributes = $reflection->getAttributes(StepOrder::class);

            if (empty($stepOrderAttributes)) {
                continue;
            }

            /** @var StepOrder $stepOrderInstance */
            $stepOrderInstance = $stepOrderAttributes[0]->newInstance();

            $stepNumber = method_exists($stepOrderInstance, 'getStepNumber')
                ? $stepOrderInstance->getStepNumber()
                : $stepOrderInstance->stepNumber;


            /** @var AbstractVideoProcessStepMessage $dummyInstance */
            $dummyInstance = $reflection->newInstanceWithoutConstructor();
            $loggingIdent = 'UNKNOWN';
            if ($reflection->hasConstant('MESSAGE_LOGGING_IDENT')) {
                $loggingIdent = (string) $reflection->getConstant('MESSAGE_LOGGING_IDENT');
            }

            $currentStatus = $dummyInstance->getVideoStatusForCurrentProcessStepEntity()->value;
            $nextMessageClass = $dummyInstance->getNextStepMessageClass();
            $shortNextClass = $nextMessageClass !== null ? $this->getShortClassName($nextMessageClass) : '-';

            $transitionName = '';
            $needsTransition = '<fg=yellow>Nein</>';
            if ($dummyInstance->nextStepNeedsTransition()){
                $needsTransition = '<fg=green>Ja</>';
                $transitionName = $dummyInstance->getTransitionToStatusNextStepIsBelonging()->name;
            }

            $pipelineSteps[$stepNumber] = [
                'step' => $stepNumber,
                'ident' => $loggingIdent,
                'class' => $this->getShortClassName($className),
                'status' => $currentStatus,
                'next_class' => $shortNextClass,
                'transition' => $needsTransition,
                'transitionName' => $transitionName,
            ];
        }

        if (empty($pipelineSteps)) {
            $io->warning('Keine Steps mit #[StepOrder]-Attribut im Message-Verzeichnis gefunden.');

            return Command::SUCCESS;
        }

        ksort($pipelineSteps);

        $table = new Table($output);
        $table->setHeaders([
            'Step #',
            'Logger Ident',
            'Message Class',
            'Video Status (Current Step)',
            'Transition to Next?',
            'By transition',
            'Next Message Class',
        ]);

        foreach ($pipelineSteps as $stepData) {
            $table->addRow([
                sprintf('<fg=cyan>%d</>', $stepData['step']),
                sprintf('<options=bold>%s</>', $stepData['ident']),
                $stepData['class'],
                $stepData['status'],
                $stepData['transition'],
                $stepData['transitionName'],
                $stepData['next_class'],
            ]);
        }

        $table->render();

        $io->newLine();
        $io->success(sprintf('Pipeline erfolgreich visualisiert. Insgesamt %d Schritte registriert.', count($pipelineSteps)));

        return Command::SUCCESS;
    }

    private function getShortClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);

        return end($parts);
    }
}