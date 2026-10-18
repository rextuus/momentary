<?php

declare(strict_types=1);

namespace App\Command\Photo;

use App\Enum\PhotoStatus;
use App\Message\AnalyzePhotoMessage;
use App\Repository\PhotoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:photo:reanalyze', description: 'Analysiert Fotos erneut (Gesichter, Tags, EXIF)')]
final class ReanalyzePhotosCommand extends Command
{
    public function __construct(
        private readonly PhotoRepository $photoRepository,
        private readonly MessageBusInterface $bus,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('photoIds', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'Foto-IDs')->addOption(
            'failed',
            null,
            InputOption::VALUE_NONE,
            'Alle fehlgeschlagenen Fotos',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $photos = $input->getOption('failed')
            ? $this->photoRepository->findBy(['status' => PhotoStatus::ERROR])
            : $this->photoRepository->findBy(['id' => array_map(intval(...), (array) $input->getArgument('photoIds'))]);

        foreach ($photos as $photo) {
            $photo->setStatus(PhotoStatus::QUEUED);
        }
        $this->entityManager->flush();
        foreach ($photos as $photo) {
            $this->bus->dispatch(new AnalyzePhotoMessage((int) $photo->getId()));
        }

        new SymfonyStyle($input, $output)->success(sprintf('%d Foto(s) zur Analyse eingeplant.', \count($photos)));

        return Command::SUCCESS;
    }
}
