<?php

namespace App\Command;

use App\Entity\Person;
use App\Repository\PersonRepository;
use App\Service\ImgproxyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:images:init-profiles',
    description: 'Befüllt die cachedImageUrl für alle Personen mit imgproxy Links.',
)]
class InitProfileImagesCommand extends Command
{
    public function __construct(
        private PersonRepository $personRepository,
        private EntityManagerInterface $entityManager,
        private ImgproxyService $imgproxyService,
        private string $internalApiHost = 'http://app'
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $persons = $this->personRepository->findAll();

        $io->progressStart(count($persons));

        foreach ($persons as $person) {
            $profileFace = $person->getProfileFace();
            if ($profileFace && $profileFace->getFaceImagePath()) {
                // Wir initialisieren nichts mehr direkt in der Entity, 
                // da getProfileImageUrl() jetzt dynamisch den Pfad zurückgibt.
                // $person->setCachedImageUrl($cachedUrl); 
            }
            $io->progressAdvance();
        }

        $this->entityManager->flush();
        $io->progressFinish();

        $io->success('Profilbilder wurden erfolgreich initialisiert.');

        return Command::SUCCESS;
    }
}
