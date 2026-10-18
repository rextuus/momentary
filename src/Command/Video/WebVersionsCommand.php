<?php

declare(strict_types=1);

namespace App\Command\Video;

use App\Enum\VideoStatus;
use App\Message\EncodeWebVersionMessage;
use App\Repository\VideoRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:video:web-versions',
    description: 'Plant Web-Fassungen (1080p, ~4 Mbit/s) für fertige Videos ein, denen sie fehlen',
)]
final class WebVersionsCommand extends Command
{
    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('ids', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Nur diese Video-IDs')->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Auch vorhandene Web-Fassungen neu erzeugen',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $ids = array_map(intval(...), (array) $input->getArgument('ids'));
        $force = (bool) $input->getOption('force');

        $videos = $ids !== []
            ? $this->videoRepository->findBy(['id' => $ids])
            : $this->videoRepository->findBy(['status' => VideoStatus::COMPLETED]);
        $count = 0;
        foreach ($videos as $video) {
            if (!$force && $video->getWebFile() !== null) {
                continue;
            }
            $this->bus->dispatch(new EncodeWebVersionMessage((int) $video->getId(), $force));
            $count++;
        }

        $io->success(sprintf(
            '%d Video(s) eingeplant; der messenger-worker erzeugt die Web-Fassungen nacheinander.',
            $count,
        ));

        return Command::SUCCESS;
    }
}
